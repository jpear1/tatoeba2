#!/bin/bash
set -e

/usr/bin/time -p -a -o /tmp/sphinx.delta.update.log /var/www-prod/bin/cake sphinx_indexes update delta >/dev/null 2>&1
