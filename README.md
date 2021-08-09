Pagewatch
=========

This is a PHP application which enables people to 'watch' pages on a website for changes and get e-mail alerts when a change happens.

Screenshot
----------

![Screenshot](screenshot.png)


Usage
-----

1. Clone the repository.
2. Download the library dependencies and ensure they are in your PHP include_path.
3. Download and install the famfamfam icon set in /images/icons/
4. Add the Apache directives in httpd.conf (and restart the webserver) as per the example given in .httpd.conf.extract.txt; the example assumes mod_macro but this can be easily removed.
5. Create a copy of the index.html.template file as index.html, and fill in the parameters.
6. Set up a cron job, as per the supplied example, pointing to the index.html file.
7. Access the page in a browser at a URL which is served by the webserver.


Dependencies
------------

* [application.php application support library](https://download.geog.cam.ac.uk/projects/application/)
* [database.php database wrapper library](https://download.geog.cam.ac.uk/projects/database/)
* [frontControllerApplication.php front controller application implementation library](https://download.geog.cam.ac.uk/projects/frontcontrollerapplication/)
* [ultimateForm.php form library](https://download.geog.cam.ac.uk/projects/ultimateform/)
* [FamFamFam Silk Icons set](http://www.famfamfam.com/lab/icons/silk/)



Daily e-mails
-------------

Invoke using PHP from the command-line under cron - see `pagewatch.cron-example` as noted above.

If not possible, `wget` is a workaround:

`wget --quiet --delete-after --user-agent="Pagewatch" https://example.com/pagewatch/runcheck.html`



Database permissions
--------------------

MySQL needs:
`GRANT SELECT,INSERT,UPDATE,DELETE ON pagewatch.* TO pagewatch@servername;`



Author
------

Martin Lucas-Smith, Department of Geography, 2003-21.


License
-------

GPL3.

