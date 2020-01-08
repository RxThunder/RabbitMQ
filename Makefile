ci:
	vendor/bin/phpstan analyse && \
	vendor/bin/php-cs-fixer fix --config=.php_cs.dist --dry-run
