
.. comment: $Id: administrator_guide.rst 129 2010-12-01 16:58:16Z s242720-studenti $

::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::
Administrator guide
::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::::

.. contents::

Installation
________________________________________________________________

Quick installation
................................................................

To install the application, decompress the archive into the public
folder of your webserver, using ``tar`` for example: ::

    $ tar fx archive.tgz

The starting page for the application is ``index.php``. If you
uncompress the archive into the webserver's public root this page
should be served to web navigators by default, otherwise, you should
provide a link to this location.

The `configuration variables`_ are available within the file
server/configuration.php in the form of php variables, the most
important variable is **$abs_root**, which points to the filesystem
position of the application root into the server. You should add to
this variable all the directories which stay between the server root
(the one which contains the main page of your website) and the
whiteboard root.

So if you want to encapsulate all the application code into a
subfolder called ``whiteboard/`` inside your server's public folders,
you should change the ``$abs_root`` variable to: ::

    $abs_root = $_SERVER['DOCUMENT_ROOT'].'/whiteboard/'.$rel_root;

You could also check the value of the ``DOCUMENT_ROOT`` variable
executing ``phpinfo()`` on your webserver, to be sure about the
correct value to give to ``$abs_root``.

Finally, to make the application working it is necessary that the
webserver user has the rights to write application files, so you
should at least change the owner of the ``data/`` folder so that it
matches with your webserver's user name, for example: ::

    chmod -R -u www-data data/

These are the main operations needed to install the application, and
the most of the time this should work, but for details see the next
sessions.

Password security
''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''

The access to the ``data/private/`` folder should be prevented to web
navigators. This depends from the configuration of your web server,
but the application package contains a ``.htaccess`` file as a try to
provide a quick simple solution.  The limits of this solution are:

- it is discouraged by the `apache
  documentation <http://httpd.apache.org/docs/2.2/howto/htaccess.html>`_
  because it reduces the performances comparing to the use of main
  server configuration directives

- it may be ignored if your apache webserver is configured with the
  option ``AllowOverride None``

- maybe you are using a webserver different from apache

You can try to access to the ``data/private/`` directory with a
browser, to see if it this access is prevented as it should be. If the
access is prevented and you don't have performance concerns, the
configuration should be fine.

Server mimetypes settings
''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''

From svgweb manual: "You must ensure that your web server has the
correct MIME settings for Flash SWF files
(application/x-shockwave-flash), Microsoft HTC files
(text/x-component), and SVG files (image/svg+xml). Since many
developers don't know what MIME types are or don't have the ability to
change this setting on their web server, SVG Web comes with a number
of utilities to make this easier".

The svgweb package includes the tools to verify the correct
configuration of your server. To help the installation, some of that
tools have been copied here into the directory ``check_mimetype`` and
slightly adapted.

If something does not works well or you doubt that mimetype settings
for your webserver are not correct, here how to do an easy check: copy
the ``config.html`` and ``helloword.svg`` files from this local
``check_mimetype/`` directory to the remote ``client/`` directory on
your webserver, then visit ``client/config.html`` with a web
browser. After you checked that all is fine, you can delete those
files.

For other details check the "Deploying SVG Web" section into the
`svgweb quick start guide
<http://codinginparadise.org/projects/svgweb/docs/QuickStart.html>`_.

File access
................................................................

The webserver running the application needs to create and edit the
application data file, so you should set the right permissions at
least to the ``data`` folder; however, the application files can be
divided into groups depending on two fundamental dimensions:

- If the content is static or it may change

- If the content needs to be publicly accessible on the webserver or
  not

Depending on these characteristics, application data can be divided
into four groups:

.. csv-table:: File group by access type
    :header: "", "Static"                 ,                           "Dynamic"
    
    "Private",   "server side source code",                           "data/private/"
    "Public",    "index.php, client side code, style sheets, images", "data/imported"

Talking about the server side code, ``index.php`` must be accessible
but the files it includes can be everywhere (simply move the files
elsewhere and change the two ``require`` statements on top of
``index.php``). Talking about the dynamic parts of the applications,
they are the files that the application updates with user and
whiteboard data: they can be moved on the filesystem operating on the
`configuration variables`_.

Coming to permissions, the dynamic files (and directories) must be
writable by the webserver user, while it is sufficient for the others
to be simply readable (obviously, the administrator should have the
rights to edit the ``permissions`` and the ``configuration.php``
files, at least).

Thus you can protect the access to your application files using both
the file permissions and their position.

Dependencies
................................................................

The core functionalities provided by the whiteboard will work without
requiring particular php extensions, but for graphics format
conversions (from pdf to images and vice-versa) the imagemagick
library for php is needed.

If the library is missing on your server, the application will disable
the affected functionalities, but it will work anyway without
complaining to the user. The users won't see the 'Import' button on
the menu, and the 'Export' button will allow only to export in pdf
form.

Configuration
________________________________________________________________


Configuration variables
................................................................

As already said, the configuration variables are some php variables
contained into the file ``server/configuration.php``. through them you
can change the application behavior concerning the following aspects:

- change the paths of data files
- enable debug
- change timeouts value

File paths
''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''

The paths are built with a sort of hierarchy. This may seem quite
tricky, but this is thought to encourage you to keep related files
near. There are two groups of file paths that should stay near because
they have similar access requirements (see `file access`_).

The first group contains private files and folders, while the second
group contains public files (that means the images). With the default
configuration, the two groups reside under the same root directory
(``data/``), but if you want to improve the application security by
decoupling the position of public and private files, just act on the
following two lines: ::

    $rel_img_root    = $rel_root;
    $abs_img_root    = $abs_root;

Substituting ``$rel_root`` and ``$abs_root`` with proper values, for
example: ::

    $rel_img_root = 'public_images/';
    $abs_img_root = $_SERVER['DOCUMENT_ROOT'].'/'.$rel_root;

Debug variables
''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''

To increase the application verbosity you can act on the variables
``$debug`` and ``$activate_log``. These variables can help either in
troubleshooting for the administrators or in debugging for the
developers; there is also a client-side debug variable (file
``application-pack.js``), but it is intended just for developers, it
shows odd messages to your users.

Debug
````````````````````````````````````````````````````````````````

This variable (``$debug``) can be useful not only for generic
troubleshooting, but also to retrieve an user's password.

When the variable is set to true, the application will write a copy
for each private file it reads, and the copy will be
human-readable. This shouldn't be a security concern, because the
security of private files isn't granted by the way they are encoded,
but rather by their accessibility.

Each whiteboard database into the ``data/private/whiteboards/``
directory will be copied, and also the password file
(``data/private/passwords``). The copies will have the same name of
the original file, with a ``-debug`` suffix. If you activate the debug
variable and then you want to deactivate it, remember to delete the
``-debug`` files, at least ``passwords-debug``.

Activate log
````````````````````````````````````````````````````````````````

The server side code uses a log file (``data/private/log``) to write a
few errors, mainly for import functions which try to get extern files
from the web or from the users, and may fail.

This variable is activated by default, meaning that you can take a
look to the log file when an user complains about, for example, a file
that he isn't able to show on the whiteboard. However, if you want to
forget this file and don't care about his growth, you can disable the
variable (set it to ``false``) and no log messages will be written at
all.

Timeout values
''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''

These variables rule the behavior of the application concerning time
intervals. Mainly, you could need to operate on these to increase the
timeout value for servers which have a slow response time.

Client timeouts
````````````````````````````````````````````````````````````````

Corresponding variables: ::

    $client_ajax_timeout
    $client_update_timeout

If the client timeouts are too short, the clients could need several
attempts in order to perform valid requests and this will turn into an
overhead on both the client and the server side. On the other hand,
there aren't strong drawbacks if the timeouts are longer more than is
necessary.

Server polling parameters
````````````````````````````````````````````````````````````````

Corresponding variables: ::

    $server_update_timeout
    $server_update_retry

You shouldn't need to change these usually. They rule the parameters
for the server `long polling
<http://en.wikipedia.org/wiki/Long_polling#Long_polling>`_. While a
client asks for updates, if there aren't any the server leaves it
waiting for an amount of time determined by
``$server_update_timeout``. During this time, the server polls
``$server_update_retry`` times his database to look for updates, and
than it sends to the client a response telling "no news".

Server and client timeouts binding
````````````````````````````````````````````````````````````````

Due to the long polling method, the part of the client software that
asks for updates has an update longer than usual, and this is the
reason why there are two variables, ``$client_ajax_timeout`` (for
generic calls) and ``$client_update_timeout`` (for update requests).

Remember that, **obviously**, the *server update timeout* must be
lesser than the *client update timeout*!

Style change
................................................................

If you can write css, the different changeable styles can be modified
through the included css files. Just change the definition of the
classes whose name includes an '``_s_``'. All those classes correspond
to a style, but the '``_s_unconfirmed``' classes which correspond to a
message sent by the chat and still not confirmed by the server.

A symple change that you could want to perform is a color change for
the application frame: this is simple to achieve operating on the
``container_div`` classes within ``style/common.css``. There is one of
such classes for each style that the user can select.

Role of data files
________________________________________________________________

To cope with the management of the application, some notion about the
role of the application data files could be useful to you (for
example, to reset user passwords or apply restriction to the users).

The permission file
................................................................

This file allows you to give permissions to the users to create,
access or delete whiteboards. The file is a list of rules, one for
each row, where you can specify users and whiteboards using a regular
expression.

For each row, three fields must be present as "delimited separed
values" file, with a **single space** as delimiter. Their meaning is
the following: ::

    <user> <whiteboard> <permissions>

Where ``<user>`` and ``<whiteboard>`` can be two (POSIX) regular
expressions, and ``<permissions>`` is a string composed by the letters
`a`, `c`, `d`, each one giving, when present, the permission to
access, create or delete, to the given user on the given whiteboard.

When a given username and whiteboard can *match different rules*, the
*first rule that contains the needed permission* is choosen.

This file is missing into the application package, so the
administrator can write one by himself. If he doesn't, the server side
code will create a file with the default rule of allowing everything
to everyone, that is a rule like this: ::

    .* .* abc

*When you want to change the file, shut down the application before,
and after the editing do some tests because the parsing is rather
fragile.* If a file contains rules which are not syntactically
correct, the permission will be denied to users, but don't rely on
this behavior.

Some examples
''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''''

Give permission to superuser to create and delete whiteboards, and to
any other to access them: ::

    superuser .* acd
    .*        .* a

Developers can create whiteboards starting with "test-", students can
create whiteboards starting with "lesson-", and the two groups can't
interfere: ::

    dev.* test-.*   acd
    s.*   lesson-.* acd

Data reset
................................................................

Unfortunately, passwords can't be reset individually now. They can only be
deleted all together, but see `debug`_ for a method to retrieve a
specific password.

That's how to cleanly reset some application data:

 - delete a whiteboard: or through the interface (after performing the
   login) or deleting the file `data/private/whiteboards/<whiteboard>`
   and the directory `data/imported/<whiteboard>`

 - ``passwords`` and ``permission`` files: just delete them, the
   application will create them with default values. **Remember** to
   delete also the **associated -lock file**, for example
   `data/private/passwords-lock`,
   `data/private/whiteboards/my_whiteboard-lock`)

Storage usage
________________________________________________________________

The application is made up from functions which are very different
among themselves, that are:

- the handling of vector graphics
- the handling of raster graphics

The consequences of this go from the load of the server processor (it
is lightly loaded when exchanging the vectorial updates, heavy loaded
while converting raster files for an export or import action), to the
space usage; indeed, the amount of space taken by the textual encoding
of the vector graphics should be much lesser than that taken by images
included by the user.

Each whiteboard manages its space, and **all its files are deleted
when the whiteboard is deleted**. If you want to know which session is
taking the most of the space, please keep in mind that each
whiteboards mantains its data into two places:

- ``data/private/whiteboards/<whiteboard name>``: It is a file
  containing user preferences for this whiteboard, all chat messages
  and all shapes encoded as strings (but not easily readable, use the
  `debug`_ variable if you want to see within this file)

- ``data/imported/<whiteboard name/``: It is a directory where all the
  included images are copied and also the imported pdf files are saved
  here as images, so it may become big.

Those are the paths with a default configuration. You can always
change the destination directories changing the corresponding
`configuration variables`_, which are in order ``$wb_dir`` and
``$image_dir``.
