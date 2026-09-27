<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use OC\AppFramework\Utility\TimeFactory;
use OC\AppFramework\Middleware\NotModifiedMiddleware;
use OC\AppFramework\Middleware\Security\CSPMiddleware;
use OC\AppFramework\Utility\ControllerMethodReflector;
use OC\Security\CSP\ContentSecurityPolicyManager;
use OC\Security\CSP\ContentSecurityPolicyNonceManager;
use OCA\RiotChat\Controller\AppController;
use OCA\RiotChat\Controller\ConfigController;
use OCA\RiotChat\Controller\SettingsController;
use OCA\RiotChat\Controller\StaticController;
use OCA\RiotChat\FileResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\NotFoundResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Defaults;
use OCP\Files\IMimeTypeDetector;
use OCP\IConfig;
use OCP\IInitialStateService;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NextcloudCompatibilityTest extends TestCase {
	private StaticController $controller;
	private string $fixtureRoot;
	private string $relativeRoot;
	private array $temporaryPaths = [];

	protected function setUp(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getServerHost')->willReturn('cloud.example.test');
		$userSession = $this->createStub(IUserSession::class);
		$server = $this->createMock(OC\Server::class);
		$server->method('get')->willReturnCallback(static function (string $service) use ($request, $userSession) {
			if ($service === ITimeFactory::class) {
				return new TimeFactory();
			}
			if ($service === IRequest::class) {
				return $request;
			}
			if ($service === IUserSession::class) {
				return $userSession;
			}
			throw new RuntimeException('Unexpected server dependency: ' . $service);
		});
		OC::$server = $server;

		$mime = $this->createStub(IMimeTypeDetector::class);
		$mime->method('detectPath')->willReturn('application/octet-stream');
		$nonce = $this->createStub(ContentSecurityPolicyNonceManager::class);
		$nonce->method('getNonce')->willReturn('bmV4dGNsb3VkLXRlc3Q=');
		$this->controller = new StaticController('riotchat', $request, $mime, $nonce,
			$this->createStub(IL10N::class), $this->createStub(IConfig::class));

		$this->relativeRoot = '.runtime-' . bin2hex(random_bytes(8));
		$this->fixtureRoot = dirname(__DIR__, 2) . '/3rdparty/riot/' . $this->relativeRoot;
		mkdir($this->fixtureRoot, 0777, true);
	}

	protected function tearDown(): void {
		foreach (array_reverse($this->temporaryPaths) as $path) {
			if (is_dir($path) && !is_link($path)) {
				rmdir($path);
			} else {
				unlink($path);
			}
		}
		rmdir($this->fixtureRoot);
	}

	private function fixture(string $name, string $content = 'fixture'): string {
		$path = $this->fixtureRoot . '/' . $name;
		file_put_contents($path, $content);
		$this->temporaryPaths[] = $path;
		return $this->relativeRoot . '/' . $name;
	}

	public function testElementAssetsHaveBrowserExecutableMimeTypes(): void {
		foreach ([
			'index.html' => 'text/html; charset=utf-8',
			'sw.js' => 'application/javascript',
			'worker.mjs' => 'application/javascript',
			'crypto.wasm' => 'application/wasm',
			'config.json' => 'application/json',
			'style.css' => 'text/css',
			'font.woff2' => 'font/woff2',
		] as $name => $mime) {
			$response = $this->controller->riot($this->fixture($name));
			self::assertInstanceOf(FileResponse::class, $response);
			self::assertSame($mime, $response->getHeaders()['Content-Type'], $name);
		}
	}

	public function testFreshConfigurationAndServiceWorkerAreNotCached(): void {
		foreach (['index.html', 'config.json', 'config.cloud.json', 'version', 'sw.js'] as $name) {
			$response = $this->controller->riot($this->fixture($name));
			self::assertStringContainsString('no-store', $response->getHeaders()['Cache-Control'] ?? '', $name);
		}
		$response = $this->controller->riot($this->fixture('bundle.js'));
		self::assertStringContainsString('max-age=3600', $response->getHeaders()['Cache-Control']);
	}

	public function testCryptoAndCallingContentSecurityPolicyMatchesElementRequirements(): void {
		$response = $this->controller->riot($this->fixture('index.html'));
		$policy = $response->getHeaders()['Content-Security-Policy'];
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
		self::assertMatchesRegularExpression('/worker-src[^;]*blob:/', $policy);
		self::assertMatchesRegularExpression('/connect-src[^;]*blob:/', $policy);
		self::assertMatchesRegularExpression('/media-src[^;]*data:/', $policy);
		self::assertMatchesRegularExpression('/frame-src[^;]*data:/', $policy);
		self::assertStringNotContainsString("'unsafe-eval'", $policy);
		self::assertStringContainsString('camera *', $response->getHeaders()['Feature-Policy']);
		self::assertStringContainsString('microphone *', $response->getHeaders()['Feature-Policy']);
	}

	public function testHtmlScriptsReceiveTheNextcloudNonceWithoutCaching(): void {
		$response = $this->controller->riot($this->fixture('index.html', '<script src="app.js"></script>'));
		$output = $this->createStub(IOutput::class);
		$output->method('getHttpResponseCode')->willReturn(200);
		ob_start();
		$response->callback($output);
		$content = ob_get_clean();
		self::assertSame('<script nonce="bmV4dGNsb3VkLXRlc3Q=" src="app.js"></script>', $content);
		self::assertSame(strlen($content), $response->getHeaders()['Content-Length']);
	}

	public function testHtmlWithANewNonceCannotBecomeANotModifiedResponse(): void {
		$path = $this->fixture('index.html', '<script src="app.js"></script>');
		$response = $this->controller->riot($path);
		$request = $this->createStub(IRequest::class);
		$modified = gmdate('D, d M Y H:i:s', filemtime($this->fixtureRoot . '/index.html')) . ' GMT';
		$request->method('getHeader')->willReturnCallback(static fn ($header) => $header === 'IF_MODIFIED_SINCE' ? $modified : '');
		$middleware = new NotModifiedMiddleware($request);
		$response = $middleware->afterController($this->controller, 'riot', $response);
		self::assertSame(200, $response->getStatus());
	}

	public function testNextcloudMiddlewarePreservesNonceAndWorkerImportsInEmittedCsp(): void {
		$response = $this->controller->riot($this->fixture('index.html'));
		$nonce = $this->createStub(ContentSecurityPolicyNonceManager::class);
		$nonce->method('getNonce')->willReturn('bmV4dGNsb3VkLXRlc3Q=');
		$nonce->method('browserSupportsCspV3')->willReturn(true);
		$manager = new ContentSecurityPolicyManager($this->createStub(IEventDispatcher::class));
		$middleware = new CSPMiddleware($manager, $nonce);
		$response = $middleware->afterController($this->controller, 'riot', $response);
		$policy = $response->getHeaders()['Content-Security-Policy'];
		self::assertStringContainsString("'nonce-bmV4dGNsb3VkLXRlc3Q='", $policy);
		self::assertMatchesRegularExpression('/(?:^|;)script-src [^;]*cloud.example.test/', $policy);
		self::assertStringContainsString("'wasm-unsafe-eval'", $policy);
	}

	public function testEveryConfiguredSsoFrameDomainIsAllowed(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static fn ($app, $key, $default = '') =>
			$key === 'sso_iframe_domain' ? "https://id.example.test   https://sso.example.test\n" : $default);
		$request = $this->createStub(IRequest::class);
		$request->method('getServerHost')->willReturn('cloud.example.test');
		$controller = new AppController('riotchat', $request, $this->createStub(IInitialStateService::class), $config);
		$policy = $controller->index()->getContentSecurityPolicy()->buildPolicy();
		self::assertStringContainsString('https://id.example.test https://sso.example.test', $policy);
		self::assertStringNotContainsString("\n", $policy);
	}

	public function testOnlySandboxedUsercontentIsPublicAndSettingsRemainAdminProtected(): void {
		$reflector = new ControllerMethodReflector(new NullLogger());
		foreach ([
			[AppController::class, 'index'],
			[ConfigController::class, 'config'],
			[ConfigController::class, 'rootConfig'],
			[StaticController::class, 'index'],
			[StaticController::class, 'riot'],
		] as [$class, $method]) {
			$reflector->reflect($class, $method);
			self::assertFalse($reflector->hasAnnotationOrAttribute('PublicPage', PublicPage::class));
			self::assertTrue($reflector->hasAnnotationOrAttribute('NoAdminRequired', NoAdminRequired::class));
		}
		$reflector->reflect(StaticController::class, 'usercontent');
		self::assertTrue($reflector->hasAnnotationOrAttribute('PublicPage', PublicPage::class));
		$reflector->reflect(SettingsController::class, 'setSetting');
		self::assertFalse($reflector->hasAnnotationOrAttribute('PublicPage', PublicPage::class));
		self::assertFalse($reflector->hasAnnotationOrAttribute('NoAdminRequired', NoAdminRequired::class));
		self::assertFalse($reflector->hasAnnotationOrAttribute('NoCSRFRequired', NoCSRFRequired::class));
	}

	public function testMissingFilesAndDirectoriesWithoutAnIndexReturn404(): void {
		self::assertInstanceOf(NotFoundResponse::class, $this->controller->riot($this->relativeRoot . '/missing.js'));
		self::assertInstanceOf(NotFoundResponse::class, $this->controller->riot($this->relativeRoot));
	}

	public function testDirectoryIndexesResolveWithAndWithoutATrailingSlash(): void {
		$this->fixture('index.html');
		self::assertInstanceOf(FileResponse::class, $this->controller->riot($this->relativeRoot));
		self::assertInstanceOf(FileResponse::class, $this->controller->riot($this->relativeRoot . '/'));
	}

	public function testSymlinksCannotServeFilesOutsideTheElementRelease(): void {
		$outside = tempnam(sys_get_temp_dir(), 'riotchat-test-');
		$this->temporaryPaths[] = $outside;
		file_put_contents($outside, 'private-data');
		$link = $this->fixtureRoot . '/outside.js';
		symlink($outside, $link);
		$this->temporaryPaths[] = $link;
		self::assertInstanceOf(NotFoundResponse::class, $this->controller->riot($this->relativeRoot . '/outside.js'));
	}

	public function testMalformedAndTraversalPathsReturn404(): void {
		foreach (['../appinfo/info.xml', "invalid\0.js", 'foo\\..\\secret'] as $path) {
			self::assertInstanceOf(NotFoundResponse::class, $this->controller->riot($path));
		}
		self::assertInstanceOf(NotFoundResponse::class, $this->controller->usercontent('../..'));
	}

	public function testConfigUsesCurrentElementSettingNamesAndDefaults(): void {
		$config = $this->createStub(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(static function ($app, $key, $default = '') {
			return $key === 'set_custom_permalink' ? 'true' : $default;
		});
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('getLanguageCode')->willReturn('fr');
		$defaults = $this->createStub(Defaults::class);
		$defaults->method('getName')->willReturn('My Cloud');
		$defaults->method('getLogo')->willReturn('/logo.svg');
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.test/apps/riotchat/');
		$controller = new ConfigController($this->createStub(IRequest::class), $l10n, $config, $defaults, $urls);
		$data = $controller->config()->getData();
		self::assertSame(['language' => 'fr'], $data['setting_defaults'] ?? null);
		self::assertTrue($data['show_labs_settings'] ?? false);
		self::assertSame('/logo.svg', $data['branding']['auth_header_logo_url'] ?? null);
		self::assertSame('https://cloud.example.test/apps/riotchat', $data['permalink_prefix'] ?? null);
		self::assertSame('https://matrix-client.matrix.org', $data['default_server_config']['m.homeserver']['base_url']);
	}
}
