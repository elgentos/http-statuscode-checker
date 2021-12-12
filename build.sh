#!/bin/bash
mkdir -p dist
[[ ! -f box.phar ]] && wget -c https://github.com/box-project/box/releases/download/3.13.0/box.phar
php -d phar.readonly=off ./box.phar compile -v && rm box.phar
