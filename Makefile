# $Id: Makefile 123 2010-11-18 12:26:04Z luigi $

# Makefile for GNU Make

.PHONY: archive data-clean clean

# Frequently used directories
S = server
C = client
D = data

default: archive

# The server side code is concatenated into an unique .php
# file, excluding just the file configuration.php which holds
# configuration variables.

$S/server.php: $S/main.php $S/updates.php $S/markup_send.php $S/draw_image.php \
               $S/fpdf.php $S/file_access.php
	@cat $+ > $@
	@echo 'server code updated'

php_files = index.php $S/server.php $S/configuration.php

# The client side code is made up by four parts: the javascript for
# the login page (login.js) the javascript for the application page
# (application-pack.js), the svgweb library (svg.js, svg.swf, svg.htc)
# and the common part between the login and application pages
# (common-pack.js). The `pack` suffix is to indicate that the file is
# created by make with a concatenation.

$C/common-pack.js: $C/common.js $C/md5.js
	@cat $+ > $@
	@echo 'client common code updated'

# shapes.js must go before whiteboard.js (because of the
# 'shape_creator' object)

$C/application-pack.js: $C/application.js $C/shapes.js $C/whiteboard.js \
	$C/chat.js $C/menu.js
	@cat $+ > $@
	@echo 'client application code updated'

js_files = $C/login.js $C/common-pack.js $C/application-pack.js $C/svg.*

other_files = style/* images/* .htaccess user_guide.html

archive: $(php_files) $(js_files) $(other_files)
	@tar --exclude .svn -cz -f archive.tgz $+ $D
	@echo 'created the file archive.tgz, ready to be uploaded on the server'

clean:
	-rm $S/server.php $C/common-pack.js $C/application-pack.js archive.tgz
