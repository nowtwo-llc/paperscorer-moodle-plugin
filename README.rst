PaperScorer's Moodle Plugin
===========================

Synchronize course rosters, grades, and quiz content between `PaperScorer`__ and
Moodle.

Compatible with Moodle versions 2.x, 3.x, 4.x, and 5.x.

__ https://paperscorer.com


Installation
============

1. Receive PaperScorer API Key.

   Contact your PaperScorer Customer Success Manager to receive the "Public Key"
   and "Secret Key". You will need these in step 8 of installation.

2. Download the latest version of the plugin from Github:
   https://github.com/paperscorer/moodle-local_paperscorer/archive/master.zip

3. Login to your Moodle instance, and under the Administration panel, expand
   *Plugins* and click *Install plugins*.

4. Under the *Install plugin from ZIP file* heading, click the *Choose a file…*
   button.

5. Click *Choose File*, select the zip file downloaded in step 2, then click
   *Upload this file*.

6. Once the zip file has been uploaded it should appear in the file list below
   the *Install plugin from ZIP file* heading. Click the *Install plugin from
   the ZIP file* button.

7. Once the plugin has been validated, click *Continue*.

8. Under the Administration panel, expand *Plugins*, then *Local plugins*, and
   click *PaperScorer Settings*. Fill in the values appropriate to your
   installation:

   ``paperscorer_launch_url``
       | ``https://app.paperscorer.com/api/moodle/launch``

   ``paperscorer_public_key``
       The public key from step 1.

   ``paperscorer_secret_key``
       The secret key from step 1.

   ``paperscorer_instance_secret``
       A secret key you have generated which *should not* be shared with
       PaperScorer. The default value is generated randomly on each page load
       and is a suitable default.

       This key is used to sign tokens sent to PaperScorer and should not be
       changed after the initial application setup.

9. Test your integration: navigate to a course, expand *Course
   administration*, then click *Launch PaperScorer*!

Note: when installing manually, the plugin directory **must** be named
``paperscorer`` (i.e. ``<moodle>/local/paperscorer``). The component name
``local_paperscorer`` is derived from the path, and ``version.php`` will fail
validation otherwise.


Usage Notes
===========

PaperScorer assumes that a student's ``idnumber`` field will be a numeric
student ID. PaperScorer will still function if it isn't, but the instructor will
have to manually assign each scanned sheet to a student.


Quiz Export
===========

PaperScorer can read a Moodle quiz's questions and answer key and import it as
an assessment. Three API actions support this.

``get_capabilities``
    Reports the plugin version, the Moodle release, and which features this
    installation supports. Call this first: an older plugin that predates quiz
    export answers with ``unknown-action``, which is the signal to hide the
    import option rather than show an error.

``list_quizzes``
    Every quiz in the course the caller may export, with ``id``, ``cmid``,
    ``name``, ``sumgrades``, ``question_count`` and ``grade_item``. Quizzes the
    caller cannot export are omitted rather than listed and refused later.

``get_quiz_structure``
    Takes ``quiz_id`` and returns the normalized questions, the answer key,
    per-question ``warnings``, and a ``skipped`` list.

``grade_item`` is the quiz activity's own gradebook column — ``id``, ``name``,
``min_mark``, ``max_mark``, ``hidden`` — or ``null`` for an ungraded quiz. Push
scores into that column rather than creating a second manual one beside it, or
the course total will double-count the assessment.

Permissions
-----------

Roster and grade actions require ``moodle/grade:edit`` in the course. Quiz
export additionally requires ``mod/quiz:manage`` on the quiz's module context —
exporting hands over every correct answer, which is a broader privilege than
editing a gradebook column, so it is scoped to users who can already see those
answers in Moodle's own quiz editor.

Supported question types
------------------------

===================  ==========================  ===============================
Moodle qtype         PaperScorer item            Notes
===================  ==========================  ===============================
``multichoice``      ``multiple_choice``         Options become A, B, C…
``truefalse``        ``true_false``              A = True, B = False
``match``            ``association_response``    Marks are divided across rows
``shortanswer``      ``writing_response``        Key kept as reference text
``numerical``        ``number_response``         Tolerance is dropped
``essay``            ``writing_response``        Rubric-scored
===================  ==========================  ===============================

Everything else — ``calculated``, ``multianswer`` (cloze), drag-and-drop types,
and random slots — is reported in ``skipped`` with a reason. A quiz containing
them still exports the questions that do map.

Degradation
-----------

Export never fails as a whole when part of a quiz cannot be represented on
paper:

* An unsupported question is skipped; the rest of the quiz still exports.
* A random slot cannot resolve to a fixed question, so it is skipped with
  ``random-question``.
* Where the conversion is lossy, the question carries a ``warnings`` entry:
  ``partial-credit-collapsed``, ``html-stripped``, ``tolerance-dropped``,
  ``hand-graded``, or ``no-correct-answer``. Review these before printing — a
  silently wrong answer key is the worst failure mode on paper.
* On a Moodle too old to resolve quiz slots, ``get_capabilities`` reports
  ``quiz_export: false`` rather than erroring.


Web service transport
=====================

The same three actions are also published as Moodle web service functions, so
a caller that already holds a web service token for the site can use them
without implementing the signed ``api.php`` protocol:

* ``local_paperscorer_get_capabilities`` — takes ``courseid``
* ``local_paperscorer_list_quizzes`` — takes ``courseid``
* ``local_paperscorer_get_quiz_structure`` — takes ``courseid`` and ``quizid``

Each returns a single ``payload`` value containing the JSON described above.
The payload is byte-identical to what ``api.php`` returns, because both
transports call the same code.

Installing the plugin registers a pre-built external service named
**PaperScorer** (shortname ``local_paperscorer``). Enable web services, then
attach a token to that service; no function list needs assembling by hand.

Permissions are unchanged: ``moodle/grade:edit`` in the course for all three,
plus ``mod/quiz:manage`` on the quiz for the export itself. On this transport
the token identifies a real Moodle user, so those checks apply to that user.


Development
===========

There is no build system and no dependency manager.

Development Moodle
------------------

``docker-compose.yml`` stands up a Moodle with this plugin bind-mounted into
it. Containers follow the PaperScorer naming convention: ``ps-moodle-php``,
``ps-moodle-database``. The site is at http://localhost:8090.

Moodle source is *not* vendored. Clone the version you want into a sibling
directory — it must live outside this repo, or mounting the repo into it would
be a recursive bind mount::

    git clone --depth 1 --branch MOODLE_405_STABLE \
      https://github.com/moodle/moodle.git ../moodle-instance
    git clone --depth 1 --branch MOODLE_502_STABLE \
      https://github.com/moodle/moodle.git ../moodle-instance-52

    cp docs/moodle-config.php.example ../moodle-instance/config.php

``.env`` selects which one runs, because the two Moodle generations differ
structurally:

===================  ==========================  ==============================
                     Moodle 4.x                  Moodle 5.x
===================  ==========================  ==============================
Web root             tree root                   ``public/``
Plugin path          ``local/paperscorer``       ``public/local/paperscorer``
Core CLI             ``admin/cli/``              ``admin/cli/`` (unchanged)
Tool CLI             ``admin/tool/``             ``public/admin/tool/``
``config.php``       tree root                   tree root
Max PHP              8.3                         8.4
===================  ==========================  ==============================

Then::

    docker compose up -d
    docker compose exec ps-moodle-php php admin/cli/install_database.php \
      --agree-license --adminpass=Paperscorer1! --adminemail=dev@example.com \
      --fullname="PaperScorer Dev" --shortname="psdev"

PHP version ceiling
-------------------

**PHP 8.4 is the maximum**, and it is Moodle's limit, not the plugin's:

* Moodle 4.x refuses to install on PHP 8.4+ (``restrict_php_version_84`` in
  ``admin/environment.xml``), so it needs 8.3.
* Moodle 5.x installs and runs fine on PHP 8.5, but its locked dependencies
  ``ezyang/htmlpurifier`` and ``openspout/openspout`` both cap at 8.4, so
  Composer refuses and **PHPUnit cannot be installed**.

The plugin's own pure logic *is* verified on PHP 8.5 — ``tests/standalone/run.php``
runs on whatever PHP is on the host. Raise ``PHP_VERSION`` in ``.env`` once
Moodle's dependencies allow it.

Tests
-----

Two layers::

    php tests/standalone/run.php     # pure logic, no Moodle, no PHPUnit

and, inside the development Moodle::

    docker compose exec ps-moodle-php php admin/tool/phpunit/cli/init.php
    docker compose exec ps-moodle-php vendor/bin/phpunit --group local_paperscorer

``init.php`` must be re-run after every ``$plugin->version`` bump — the test
environment records the version it was built against and refuses to run
otherwise.

Test classes must use the **namespaced** convention: a file ``tests/foo_test.php``
declares ``namespace local_paperscorer;`` and ``class foo_test extends \advanced_testcase``.
Moodle 5.x will not discover the older global ``local_paperscorer_foo_testcase``
naming; the namespaced form works on both 4.x and 5.x.

Syntax-check any changed file with ``php -l <file>``. Bump ``$plugin->version``
in ``version.php`` after any change or Moodle will not re-install the plugin.

Packaging
---------

The repo is the deployed artifact, so a release zip must exclude the
development files and use ``paperscorer`` as its root directory name::

    rm -rf /tmp/pkg && mkdir -p /tmp/pkg/paperscorer
    rsync -a --exclude '.git' --exclude 'docs' --exclude 'docker-compose.yml' \
      ./ /tmp/pkg/paperscorer/
    (cd /tmp/pkg && zip -r paperscorer.zip paperscorer)
