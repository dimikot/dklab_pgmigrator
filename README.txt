dklab_pgmigrator: complete PostgreSQL live scheme migration tool
(C) Dmitry Koterov, http://en.dklab.ru/lib/dklab_pgmigrator/
(C) Miroslav Sulc, http://apgdiff.startnet.biz

This tool allows you to keep in sync a production database scheme
with a develpoment database scheme. Any changes you made in development
database may be deployed to production database in almost automatical 
way, and you take control over the whole process.


Installation
------------

1. Apply dklab_pgmigrator.sql to both development and production databases.

2. Copy dklab_pgmigrator.php & apgdiff*.jar anywhere you want.

3. Create private & public SSH keys which allows you to run

     $ ssh postgres@HOST pg_dump DB

   without a password, where HOST and DB are both developer & production
   hosts & database name. (Dklab_pgmigrator uses ph_dump over SSH, because
   it is much faster than all other dumping methods.)

4. Enjoy!


Usage
-----

Suppose you have 2 databases DB at hosts PROD.HOST (production) and DEV.HOST 
(development). Assume you just launched the project, so PROD.HOST is equal 
to DEV.HOST.

Typical usecase is the following:

1. You make ANY changes in DEV database: create tables, stored functions,
   views, triggers etc. - anything you want, without limitation.
   
2. You run:

   $ mkdir -p ./DB_migrations # e.g.
   $ php dklab_pgmigrator.php --dev=DEV.HOST/DB --prod=PROD.HOST/DB \
                              --dir=./DB_migrations
   
   (run with no arguments to see its usage).
   
3. Dklab_pgmigrator examines changes between PROD.HOST and DEV.HOST and
   generates a "migration script" in ./DB_migration directory. This
   script contains ALTERs which are needed to transform PROD.HOST database
   scheme to DEV.HOST state.
   
4. Important: dklab_pgmigrator validates that this migration script really 
   works and the resulting scheme is equal to DEV.HOST scheme. If any errors 
   occurred, they are displayed, and no "migration script" is created, so 
   you may correct errors and retry the process from step 3 (see below 
   "Error correction and custom commands" section).
   
5. At the end, you get ./DB_migration/2010-05-06-13-03-17-mig/10_ddl.sql file
   which contains ALTERs to transform PROD.HOST scheme to DEV.HOST state.
   You may commit it to your version control system.
   
6. All generated "migration scripts" update scheme version marker, so when 
   you perform a real deployment, you just need to call "psql" for all
   scripts which have versions more than the current HOST.PROD version.

Note that dklab_pgmigrator watches over HOST.PROD database scheme version 
and does not apply same migrations twice. So you may generate a "migration 
script", then modify HOST.DEV database again (without touching of HOST.PROD) 
and generate another "migration script": the second script will not contain
changes which are already applied by the previous.


Error correction and custom commands
------------------------------------

The world is not perfect, and, in spite of apgdiff tool is really great,
sometimes it generates a wrong ALTERs set. Also apgdiff does not support
enum elements addition/removal (frankly, PostgreSQL does not support it
too, but you may find a work-around at http://dklab.ru/lib/dklab_postgresql_enum/ ).

So, there is a way to manually correct "migration script" creation procedure.
If you create a directory ./DB_migration/YYYY-MM-DD-HH-mm-SS (where
YYYY-MM-DD-HH-mm-SS is current date and time) and place any *.sql files in 
it, these SQL files will be included at the beginning of a next "migration 
script" and correct it.

Let's consider an example. Suppose you RENAMED a column tbl.col to
tbl.col_other. When you generate a "migration script", you see that
there are two ALTERs instead of one RENAME:

ALTER TABLE tbl DROP COLUMN col;
ALTER TABLE tbl ADD COLUMN col_other ...;

This is WRONG, but apgdiff does not support columns remaming (it is technically
impossible), so you may correct it manually:

1. Create ./DB_migration/YYYY-MM-DD-HH-mm-SS

2. Create the file ddl.sql and place the following code into it:

   ALTER TABLE tbl RENAME COLUMN col TO col_other;
   
3. Run dklab_pgmigrator. You will see that this ALTER is embedded in the
   beginning of "migration script", and there are no more wrong ALTERs.
