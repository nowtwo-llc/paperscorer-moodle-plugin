===========================
PaperScorer Moodle Plugin
===========================

``local_paperscorer`` connects a self-hosted Moodle site to `PaperScorer`__.
Once installed and connected, PaperScorer can:

* list a teacher's courses and import their rosters,
* import a Moodle quiz (questions and answer key) as a PaperScorer assessment,
* write scores back into the gradebook.

A self-hosted site needs only this plugin. Sites on MoodleCloud, which cannot
install plugins, follow the separate MoodleCloud setup guide instead.

Compatible with Moodle 2.7 through 5.x.

__ https://paperscorer.com


Quick start
===========

1. **Get your keys.** Ask your PaperScorer Customer Success Manager for the
   *Public Key* and *Secret Key* for your site.

2. **Install the plugin.** Download the latest release zip and, in Moodle, go
   to *Site administration → Plugins → Install plugins*, upload the zip, and
   follow the prompts. The plugin folder inside the zip must be named
   ``paperscorer`` (see `Packaging`_).

3. **Configure it.** Go to *Site administration → Plugins → Local plugins →
   PaperScorer Settings* and fill in the values from the `Settings`_ table
   below.

4. **Connect the site to PaperScorer.** Installing the plugin registers a
   ready-made external service named **PaperScorer**. Enable web services and
   create one token on that service; PaperScorer needs nothing else.

   a. *Site administration → General → Advanced features*: turn on
      **Enable web services**.
   b. *Site administration → Server → Web services → Manage protocols*:
      enable **REST**.
   c. *Site administration → Server → Web services → Manage tokens*: create a
      token for an administrator (or a manager who can edit grades in every
      course PaperScorer will sync) on the **PaperScorer** service.
   d. In PaperScorer, enter your Moodle site URL and that token.

5. **Test it.** Open any course, expand *Course administration*, and click
   **Launch PaperScorer**.


Settings
========

All settings live under *Site administration → Plugins → Local plugins →
PaperScorer Settings*.

======================================  =====================================================
Setting                                 Value
======================================  =====================================================
``paperscorer_launch_url``              ``https://app.paperscorer.com/api/moodle/launch``
``paperscorer_public_key``              The public key from PaperScorer.
``paperscorer_secret_key``              The secret key from PaperScorer.
``paperscorer_instance_secret``         A random secret generated for you on first view.
                                        **Do not share it with PaperScorer, and do not change
                                        it after setup**: it signs every per-user key the
                                        plugin issues, and changing it invalidates them all.
``paperscorer_student_id_field``        Which profile field is the bubble-sheet student ID:
                                        ``idnumber`` (default), the Moodle user id, or any
                                        custom profile field. Custom field values are reduced
                                        to their digits.
``paperscorer_open_in_new_window``      Open PaperScorer in a new window on launch.
``paperscorer_enable_student_launch``   Show the launch link to students too, so they can
                                        reach online assessments from Moodle.
======================================  =====================================================

PaperScorer expects the student ID to be numeric. If it is not, scanned sheets
still work, but the instructor has to match each sheet to a student by hand.


Permissions
===========

The plugin defines no capabilities of its own. It reuses Moodle's:

===========================  ==================================================================
Capability                   Grants
===========================  ==================================================================
``moodle/grade:edit``        Every roster and grade action in that course, and the launch
in the course                link for instructors. ``list_courses`` returns only courses
                             where the user holds it.
``mod/quiz:manage``          Exporting that quiz's questions and answer key. Required in
on the quiz                  addition to the course capability, because handing over an
                             answer key is a broader privilege than editing a gradebook
                             column. ``list_quizzes`` omits quizzes the caller cannot export.
===========================  ==================================================================

When a service-account token looks up another user's courses, the token's
user must hold ``moodle/grade:edit`` in each course as well, so a token never
reveals a course it could not itself sync.

Grade writes may target a manual grade item or an activity's own gradebook
column (recorded as an override). Course and category totals are refused.
Only manual items can be created or edited.


How it works
============

The plugin has two halves.

**Launch.** *Launch PaperScorer* in a course opens PaperScorer with a signed
payload identifying the user and course. It also carries a per-user key
derived from ``paperscorer_instance_secret``, which is what lets PaperScorer
call back into Moodle on that user's behalf.

**API.** PaperScorer's servers read and write Moodle data through Moodle's
web services, using the token created in `Quick start`_ step 4. The same
actions are also reachable through the plugin's own signed endpoint,
``api.php``, for launch-based use. Both transports run the same code and
return identical payloads.


API reference
=============

Web service functions
---------------------

Every function is a Moodle web service function on the **PaperScorer**
service, callable over REST at ``/webservice/rest/server.php``. Each returns a
single ``payload`` value containing JSON.

==============================================  =============================  ======================================================
Function                                        Parameters                     Returns
==============================================  =============================  ======================================================
``local_paperscorer_list_courses``              ``userid`` (0 = token user)    Courses that user can sync: ``id``, ``label``
                                                                               (full name), ``name`` (short name), ``idnumber``,
                                                                               ``visible``.
``local_paperscorer_get_roster``                ``courseid``                   ``sections`` (always empty) and ``students``: each
                                                                               with ``student_id``, ``name`` (``Last; First``) and
                                                                               ``fields`` holding ``lms_user_id``, ``lms_email``,
                                                                               ``lms_username`` and ``lms_roles``.
``local_paperscorer_list_grade_items``          ``courseid``                   The manual grade items: ``id``, ``name``,
                                                                               ``min_mark``, ``max_mark``, ``hidden``.
``local_paperscorer_create_update_grade_item``  ``courseid``, ``item``         The saved item. ``item`` carries ``name``,
                                                                               ``min_mark``, ``max_mark`` and an optional ``id``
                                                                               of an existing manual item to edit.
``local_paperscorer_update_grades``             ``courseid``, ``itemid``,      One ``{lms_user_id, success}`` per update.
                                                ``updates``                    ``updates`` is a list of ``{lms_user_id, mark}``.
``local_paperscorer_get_capabilities``          ``courseid``                   Plugin version, Moodle release, and a ``features``
                                                                               map (``roster``, ``grades``, ``quiz_export``).
``local_paperscorer_list_quizzes``              ``courseid``                   Exportable quizzes: ``id``, ``cmid``, ``name``,
                                                                               ``sumgrades``, ``question_count``, ``grade_item``.
``local_paperscorer_get_quiz_structure``        ``courseid``, ``quizid``       The normalized quiz (see `Quiz export`_).
==============================================  =============================  ======================================================

The service also includes the core functions PaperScorer's connect and sync
flow uses, so one token covers everything:

``core_webservice_get_site_info``, ``core_user_get_users_by_field``,
``core_enrol_get_users_courses``, ``core_enrol_get_enrolled_users``,
``core_course_get_courses_by_field``, ``core_course_get_contents``,
``mod_assign_save_grade``.

Moodle records service function names without checking they exist, so a core
function that an older release lacks is harmless; it simply cannot be called
there.

Quiz export
-----------

Call ``get_capabilities`` first. A plugin too old to export quizzes answers
``unknown-action``; a Moodle too old to resolve quiz slots reports
``quiz_export: false``. Either is the signal to hide the import option rather
than show an error.

``get_quiz_structure`` returns the quiz, its ``questions``, per-question
``warnings``, and a ``skipped`` list. ``grade_item`` is the quiz's own
gradebook column, or ``null`` for an ungraded quiz. Push scores into that
column with ``update_grades`` rather than creating a second manual item beside
it, or the course total will count the assessment twice.

Supported question types:

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

Export degrades rather than failing:

* Unsupported types (``calculated``, ``multianswer``, drag-and-drop, and
  others) and random slots land in ``skipped`` with a reason. The rest of the
  quiz still exports.
* Lossy conversions add a ``warnings`` entry to the question:
  ``partial-credit-collapsed``, ``html-stripped``, ``tolerance-dropped``,
  ``hand-graded`` or ``no-correct-answer``. Review these before printing; a
  silently wrong answer key is the worst failure mode on paper.

Signed transport
----------------

``api.php`` exposes the same actions to a caller holding a per-user key from
the launch payload. A request carries ``ps_key`` (the Moodle user id),
``ps_signature``, ``ps_expires`` and ``action`` (JSON with a ``name`` and the
action's parameters). The signature is an HMAC-SHA1 over
``"<expires>\n<METHOD>\n<action>"``, plus ``"\n<request body>"`` on POST, keyed
with the per-user key.

Action names match the web service functions without the prefix:
``list_courses``, ``get_roster``, ``list_grade_items``,
``create_update_grade_item``, ``update_grades``, ``get_capabilities``,
``list_quizzes``, ``get_quiz_structure``, plus ``selftest``.


Development
===========

There is no build system and no dependency manager. The repository is the
deployed artifact.

Development Moodle
------------------

``docker-compose.yml`` stands up a Moodle with this plugin bind-mounted into
it (containers ``ps-moodle-php`` and ``ps-moodle-database``, site at
http://localhost:8090).

Moodle source is not vendored. Clone the version you want into a sibling
directory; it must live outside this repo, or mounting the repo into it would
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
Core CLI             ``admin/cli/``              ``admin/cli/``
Tool CLI             ``admin/tool/``             ``public/admin/tool/``
``config.php``       tree root                   tree root
Max PHP              8.3                         8.4
===================  ==========================  ==============================

Then::

    docker compose up -d
    docker compose exec ps-moodle-php php admin/cli/install_database.php \
      --agree-license --adminpass=Paperscorer1! --adminemail=dev@example.com \
      --fullname="PaperScorer Dev" --shortname="psdev"
    docker compose exec ps-moodle-php chown -R www-data:www-data /var/www/moodledata

The last line matters: the CLI install runs as root and leaves the data
directory unwritable by Apache.

PHP version ceiling
-------------------

**PHP 8.4 is the maximum**, and it is Moodle's limit, not the plugin's:

* Moodle 4.x refuses to install on PHP 8.4+ (``restrict_php_version_84``), so
  it needs 8.3.
* Moodle 5.x runs on PHP 8.5, but its locked dependencies
  ``ezyang/htmlpurifier`` and ``openspout/openspout`` cap at 8.4, so PHPUnit
  cannot be installed there.

The plugin's own pure logic is verified on PHP 8.5 by
``tests/standalone/run.php``, which uses the host PHP.

Tests
-----

Two layers::

    php tests/standalone/run.php     # pure logic, no Moodle, no PHPUnit

and, inside the development Moodle::

    docker compose exec ps-moodle-php php admin/tool/phpunit/cli/init.php
    docker compose exec ps-moodle-php vendor/bin/phpunit --group local_paperscorer

``init.php`` must be re-run after every ``$plugin->version`` bump; the test
environment records the version it was built against and refuses to run
otherwise.

Test classes use the namespaced convention: ``tests/foo_test.php`` declares
``namespace local_paperscorer;`` and ``class foo_test extends
\advanced_testcase``. Moodle 5.x does not discover the older global naming.

To exercise the web service functions over real HTTP, enable web services and
REST on the dev site, create a token on the ``local_paperscorer`` service, and
call ``http://localhost:8090/webservice/rest/server.php`` from the host. Pass
``-g`` to curl, or it treats the bracketed parameter names (``item[name]``) as
globs and the request silently fails.

Syntax-check any changed file with ``php -l <file>``. Bump ``$plugin->version``
in ``version.php`` after any change, or Moodle will not re-install the plugin.
Adding or renaming a web service function also needs the bump; Moodle only
re-reads ``db/services.php`` on upgrade.

Packaging
---------

The repo is the deployed artifact, so a release zip must exclude the
development files and use ``paperscorer`` as its root directory name::

    rm -rf /tmp/pkg && mkdir -p /tmp/pkg/paperscorer
    rsync -a --exclude '.git' --exclude 'docs' --exclude 'docker-compose.yml' \
      --exclude 'docker' --exclude '.env' --exclude 'CLAUDE.md' \
      ./ /tmp/pkg/paperscorer/
    (cd /tmp/pkg && zip -r paperscorer.zip paperscorer)
