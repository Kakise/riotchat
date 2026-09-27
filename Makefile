SHELL := /bin/bash
.DEFAULT_GOAL := all

.PHONY: all dev-setup element build clean distclean dist source appstore test-packaging

all: dev-setup
	$(MAKE) build

# Install the adapter's locked dependencies. Element itself is a verified release.
dev-setup:
	npm ci

element:
	python3 scripts/fetch-element.py

build: element
	npm run build

clean:
	rm -rf build

distclean: clean
	rm -rf node_modules js 3rdparty/riot

dist: appstore source

appstore:
	bash scripts/release.sh

source:
	bash scripts/release.sh --source

test-packaging:
	python3 tests/packaging_test.py
