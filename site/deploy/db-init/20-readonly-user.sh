# Sourced (not executed) by the mariadb image's entrypoint on first start,
# after the dump in 10-mediawiki.sql.gz has been imported. Keep this file
# non-executable so the entrypoint sources it and docker_process_sql exists.
#
# The public web container connects as wiki_ro, which can only SELECT, so
# even a compromised MediaWiki can't change the wiki's content.
docker_process_sql --database=mysql <<-EOSQL
	CREATE USER IF NOT EXISTS 'wiki_ro'@'%' IDENTIFIED BY '${DB_RO_PASSWORD}';
	GRANT SELECT ON \`${MARIADB_DATABASE}\`.* TO 'wiki_ro'@'%';
EOSQL
