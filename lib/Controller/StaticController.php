<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020-2021 Gary Kim <gary@garykim.dev>
 * @copyright Copyright (c) 2019 Robin Appelman <robin@icewind.nl>
 * @copyright Copyright (c) 2024 Vincent Siebert <vincent@siebert.ovh>
 *
 * @author 2020 Gary Kim <gary@garykim.dev>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\RiotChat\Controller;

use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\RiotChat\FileResponse;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\Files\IMimeTypeDetector;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IRequest;

class StaticController extends Controller {

	/** @var IMimeTypeDetector */
	private $mimeTypeHelper;

	/**
	 * Nextcloud has no public nonce service. Use the same manager as its CSP
	 * middleware so Element's entry scripts receive the response's nonce.
	 * @var ContentSecurityPolicyNonceManager
	 */
	private $nonceManager;

	/** @var IL10N */
	private $l10n;

	/** @var IConfig */
	private $config;

	/**
	 * StaticController constructor.
	 *
	 * @param $appName
	 * @param IRequest $request
	 * @param IMimeTypeDetector $mimeTypeHelper
	 * @param ContentSecurityPolicyNonceManager $nonceManager
	 * @param IL10N $l10n
	 * @param IConfig $config
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IMimeTypeDetector $mimeTypeHelper,
		ContentSecurityPolicyNonceManager $nonceManager,
		IL10N $l10n,
		IConfig $config,
	) {
		parent::__construct($appName, $request);

		$this->mimeTypeHelper = $mimeTypeHelper;
		$this->nonceManager = $nonceManager;
		$this->l10n = $l10n;
		$this->config = $config;
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function index() {
		return $this->riot('index.html');
	}

	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function riot(string $path): FileResponse|NotFoundResponse {
		if (str_contains($path, "\0") || str_contains($path, '\\') || in_array('..', explode('/', $path), true)) {
			return new NotFoundResponse();
		}

		$root = realpath(__DIR__ . '/../../3rdparty/riot');
		if ($root === false) {
			return new NotFoundResponse();
		}
		$localPath = $root . '/' . ltrim($path, '/');
		if (is_dir($localPath)) {
			$localPath .= '/index.html';
		}
		$localPath = realpath($localPath);
		if ($localPath === false || !str_starts_with($localPath, $root . '/') || !is_file($localPath) || !is_readable($localPath)) {
			return new NotFoundResponse();
		}

		return $this->createFileResponse($localPath);
	}

	/**
	 * Special route for '/bundles/{hash}/usercontent.js' as that request is made without authentication
	 *
	 * @param string $version
	 * @return FileResponse|NotFoundResponse
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[NoAdminRequired]
	public function usercontent(string $version) {
		if (!preg_match('/^[a-zA-Z0-9_-]+$/D', $version)) {
			return new NotFoundResponse();
		}
		return $this->riot('bundles/' . $version . '/usercontent.js');
	}

	/**
	 * @param $path
	 * @return FileResponse|NotFoundResponse
	 */
	private function createFileResponse($path) {
		$content = file_get_contents($path);
		if ($content === false) {
			return new NotFoundResponse();
		}
		return $this->createFileResponseWithContent($path, $content);
	}

	/**
	 * @param string $path
	 * @param string $content
	 * @param bool $cache
	 * @return FileResponse
	 */
	private function createFileResponseWithContent(string $path, string $content, $cache = true) {
		$isHTML = pathinfo($path, PATHINFO_EXTENSION) === 'html';
		if ($isHTML) {
			$content = $this->addScriptNonce($content, $this->nonceManager->getNonce());
		}

		// Nextcloud's detector is intended for user files; executable web assets
		// need explicit MIME types, especially service workers and WebAssembly.
		$mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
			'html' => 'text/html; charset=utf-8',
			'js', 'mjs' => 'application/javascript',
			'css' => 'text/css',
			'json', 'map' => 'application/json',
			'wasm' => 'application/wasm',
			'woff' => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf' => 'font/ttf',
			'otf' => 'font/otf',
			'svg' => 'image/svg+xml',
			default => $this->mimeTypeHelper->detectPath($path),
		};

		$response = new FileResponse(
			$content,
			strlen($content),
			filemtime($path),
			$mime,
			basename($path)
		);

		// Entry points, translations and configuration must refresh after updates.
		// HTML also contains the per-request Nextcloud CSP nonce.
		$refreshOnLoad = $isHTML || pathinfo($path, PATHINFO_EXTENSION) === 'json'
			|| str_contains($path, '/i18n/') || in_array(basename($path), ['version', 'sw.js'], true);
		if ($cache && !$refreshOnLoad) {
			$response->cacheFor(3600);
		} else {
			$response->cacheFor(0);
			// In particular, never reuse HTML with an older CSP nonce via a 304.
			$response->setLastModified(null);
		}

		$csp = new ContentSecurityPolicy();
		$csp->allowEvalWasm(true);
		// Nextcloud removes 'self' from script-src when adding its nonce.
		// Workers still need the explicit host for same-origin importScripts.
		$csp->addAllowedScriptDomain($this->request->getServerHost());
		$csp->addAllowedScriptDomain('https://www.recaptcha.net/recaptcha/');
		$csp->addAllowedScriptDomain('https://www.gstatic.com/recaptcha/');

		// TODO: Slowly make the CSP more strict if `disable_custom_urls` is set. https://github.com/gary-kim/riotchat/issues/23#issuecomment-623920519 https://github.com/gary-kim/riotchat/blob/823260fdbc0d23d07c5413b436221bd0f49f6da9/lib/Controller/StaticController.php#L157-L164
		$csp->addAllowedConnectDomain('*');
		$csp->addAllowedConnectDomain('blob:');
		$csp->addAllowedImageDomain('*');
		$csp->addAllowedMediaDomain('*');
		$csp->addAllowedMediaDomain('blob:');
		$csp->addAllowedMediaDomain('data:');
		$csp->addAllowedFrameDomain('blob:');
		$csp->addAllowedFrameDomain('data:');
		$csp->addAllowedWorkerSrcDomain("'self'");
		$csp->addAllowedWorkerSrcDomain('blob:');

		// Needs to include current domain and the Jitsi instance being used
		$csp->addAllowedFrameDomain('*');

		$response->setContentSecurityPolicy($csp);

		$featurePolicy = new FeaturePolicy();
		$featurePolicy->addAllowedCameraDomain('*');
		$featurePolicy->addAllowedMicrophoneDomain('*');

		$response->setFeaturePolicy($featurePolicy);

		return $response;
	}

	private function addScriptNonce(string $content, string $nonce): string {
		return str_replace('<script', "<script nonce=\"$nonce\"", $content);
	}
}
