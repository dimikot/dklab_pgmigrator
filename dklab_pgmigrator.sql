CREATE SCHEMA migration;

CREATE TYPE migration.enum_migration_version AS ENUM (
    '2010-01-01-00-00-00-mig'
);

CREATE FUNCTION migration.migration_version_get() RETURNS character varying
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN enum_last(NULL::migration.enum_migration_version)::VARCHAR;
END;
$$;

CREATE FUNCTION migration.migration_version_set(in_version character varying) RETURNS void
    LANGUAGE plpgsql
    AS $_$
BEGIN
    IF in_version !~ E'^\\d{4}-\\d{2}-\\d{2}-\\d{2}-\\d{2}-\\d{2}-mig$' THEN
        RAISE EXCEPTION 'Version must be started with a date format, "%" given', in_version;
    END IF;
    IF migration.migration_version_get() >= in_version THEN
        RAISE EXCEPTION 'Attempt to set version "%" which is not above the current: "%"', in_version, migration.migration_version_get();
    END IF;
    EXECUTE 'DROP TYPE migration.enum_migration_version';
    EXECUTE 'CREATE TYPE migration.enum_migration_version AS ENUM(' || quote_literal(in_version) || ')';
END;
$_$;
