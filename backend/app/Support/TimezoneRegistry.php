<?php

namespace App\Support;

/**
 * The plant timezone, chosen during installation and stored in the database.
 *
 * Every rendered timestamp, report boundary and shift edge is expressed in it —
 * get it wrong and "today's production" covers different hours than the shift the
 * floor actually worked.
 *
 * Why the database and not `APP_TIMEZONE`
 * ---------------------------------------
 * A real environment variable always beats the `.env` file: Laravel bootstraps
 * Dotenv against `Env::getRepository()`, which does not overwrite variables that
 * already exist in the process. `docker-compose.yml` sets `APP_TIMEZONE` on every
 * service (defaulting to UTC), so on a Docker install — the deployment the
 * implementation runbook describes — a value written into `.env` is read and then
 * silently ignored. Storing the choice here is what makes it settable from the
 * installer and, later, changeable without editing compose files or restarting.
 *
 * `APP_TIMEZONE` still works and is still respected: it is the fallback whenever
 * this setting is absent, so existing installs and bare-metal deployments keep
 * behaving exactly as before.
 */
class TimezoneRegistry
{
    public const SETTING_KEY = 'app_timezone';

    /** Cached per process: this is read on every boot, including Octane workers. */
    private static ?string $cached = null;

    /**
     * The configured plant timezone, or null when the installation has not chosen
     * one (fresh install, or the DB is not reachable yet during install).
     */
    public static function stored(): ?string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $timezone = SystemSetting::get(self::SETTING_KEY);

        if (! is_string($timezone) || ! self::isValid($timezone)) {
            return null;
        }

        return self::$cached = $timezone;
    }

    /**
     * Apply the stored timezone to the running application.
     *
     * Both halves are needed: `config('app.timezone')` is what the Inertia
     * `timezone` prop and anything reading config sees, and
     * `date_default_timezone_set()` is what Carbon and PHP's date functions use.
     */
    public static function apply(): void
    {
        $timezone = self::stored();

        if ($timezone === null) {
            return;
        }

        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
    }

    /** Persist the choice. Invalid identifiers are refused rather than stored. */
    public static function save(string $timezone): void
    {
        if (! self::isValid($timezone)) {
            throw new \InvalidArgumentException("Unknown timezone identifier: {$timezone}");
        }

        SystemSetting::put(self::SETTING_KEY, $timezone);

        self::$cached = $timezone;
    }

    public static function isValid(string $timezone): bool
    {
        return in_array($timezone, self::identifiers(), true);
    }

    /**
     * Every IANA identifier PHP knows, so a plant anywhere can be configured.
     *
     * @return array<int, string>
     */
    public static function identifiers(): array
    {
        return \DateTimeZone::listIdentifiers();
    }

    /**
     * Identifiers grouped by region, for an <optgroup> select.
     *
     * @return array<string, array<int, string>>
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (self::identifiers() as $identifier) {
            // "Europe/Warsaw" -> "Europe"; "UTC" has no region of its own.
            $region = str_contains($identifier, '/') ? explode('/', $identifier)[0] : 'Other';
            $grouped[$region][] = $identifier;
        }

        ksort($grouped);

        return $grouped;
    }

    /**
     * What the installer should preselect: whatever the application is already
     * running on, so a deployment that set APP_TIMEZONE sees its own value.
     */
    public static function current(): string
    {
        return self::stored() ?? (string) config('app.timezone', 'UTC');
    }

    /** Test seam — the cache is per process and would leak between tests. */
    public static function flush(): void
    {
        self::$cached = null;
    }
}
