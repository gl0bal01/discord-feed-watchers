SHELL := /bin/bash

PHP ?= php

WATCHER_SCRIPTS := \
	cve/cve.php \
	europol/europol.php \
	fbi/fbi.php \
	fun/fun.php \
	ransomware/ransomware.php

CORE_SCRIPTS := \
	src/lib/WatchlistRuntime.php \
	src/config/config.example.php

PHP_SCRIPTS := $(WATCHER_SCRIPTS) $(CORE_SCRIPTS)

.PHONY: help check-deps lint run-cve run-europol run-fbi run-fun run-ransomware run-all

help:
	@echo "Available targets:"
	@echo "  make check-deps       Check PHP and curl extension availability"
	@echo "  make lint             Run PHP syntax checks on project scripts"
	@echo "  make run-cve          Run CVE watcher"
	@echo "  make run-europol      Run Europol watcher"
	@echo "  make run-fbi          Run FBI watcher"
	@echo "  make run-fun          Run Daily Fun watcher"
	@echo "  make run-ransomware   Run Ransomware watcher"
	@echo "  make run-all          Run all watchers sequentially"

check-deps:
	@command -v $(PHP) >/dev/null 2>&1 || { echo "Error: '$(PHP)' not found in PATH"; exit 1; }
	@$(PHP) -m | grep -iq '^curl$$' || { echo "Error: PHP extension 'curl' is not enabled"; exit 1; }
	@echo "Dependencies OK"

lint: check-deps
	@for file in $(PHP_SCRIPTS); do \
		echo "Linting $$file"; \
		$(PHP) -l $$file || exit 1; \
	done

run-cve: check-deps
	@$(PHP) cve/cve.php

run-europol: check-deps
	@$(PHP) europol/europol.php

run-fbi: check-deps
	@$(PHP) fbi/fbi.php

run-fun: check-deps
	@$(PHP) fun/fun.php

run-ransomware: check-deps
	@$(PHP) ransomware/ransomware.php

run-all: run-cve run-europol run-fbi run-fun run-ransomware
