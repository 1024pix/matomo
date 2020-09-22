#!/bin/bash

echo "Generating Matomo configuration from environment"

php ./config/generate.config.ini.php

bin/install_purchased_plugins.sh

exec bash bin/run
