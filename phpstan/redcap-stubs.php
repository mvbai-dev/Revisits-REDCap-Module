<?php
/**
 * Platzhalter für REDCap und das External-Module-Framework, nur für PHPStan.
 *
 * REDCap selbst darf nicht ins Repo (Lizenz). Diese Datei deklariert deshalb
 * nur Namen und grobe Signaturen der Teile, die die Module tatsächlich nutzen,
 * damit PHPStan den eigenen Code prüfen kann, statt über fehlende Klassen zu
 * stolpern. Sie enthält keinen REDCap-Code und wird nie in REDCap geladen.
 *
 * Bewusst so gebaut:
 * - Framework-Methoden von AbstractExternalModule sind einzeln deklariert (kein
 *   __call): Tippfehler in Methodennamen des eigenen Moduls fallen weiter auf.
 *   Nutzt ein Modul eine neue Framework-Methode, hier ergänzen.
 * - REDCap-Core-Klassen (REDCap, UserRights, ...) erlauben jede statische
 *   Methode (__callStatic) und liefern mixed - deren Signaturen prüft PHPStan
 *   damit nicht, nur unseren Umgang mit den Ergebnissen.
 */

namespace ExternalModules {

    abstract class AbstractExternalModule
    {
        /** @var string */
        public $PREFIX = '';
        /** @var string */
        public $VERSION = '';
        /** @var mixed */
        public $framework;

        /** @return mixed */
        public function getProjectSetting(string $key, $projectId = null) { return null; }
        /** @return mixed */
        public function getSystemSetting(string $key) { return null; }
        /** @param mixed $value */
        public function setProjectSetting(string $key, $value, $projectId = null): void {}
        /** @return array<int, array<string, mixed>> */
        public function getSubSettings(string $key, $projectId = null): array { return []; }
        /** @return string|null */
        public function getProjectId() { return null; }
        /** @return mixed */
        public function getUser($username = null) { return null; }
        public function getUrl(string $path, bool $noAuth = false, bool $useApiEndpoint = false): string { return ''; }
        public function getCSRFToken(): string { return ''; }
        /** @param array<mixed>|null $parameters */
        public function query(string $sql, $parameters = null): \mysqli_result { throw new \Exception(); }
        /** @return mixed */
        public function createQuery() { return null; }
        /** @param array<string, mixed> $parameters */
        public function log(string $message, array $parameters = []): int { return 0; }
        public function getDataTable($projectId): string { return ''; }
        public function getEdocPath($edocId): string { return ''; }
        public function createTempFile(): string { return ''; }
        /** @return int|string|false */
        public function saveFile(string $path, $projectId = null) { return 0; }
        /** @return array<int, string> */
        public function getProjectsWithModuleEnabled(): array { return []; }
        public function isRoute(string $route): bool { return false; }
        public function initializeJavascriptModuleObject(): string { return ''; }
        public function getJavascriptModuleObjectName(): string { return ''; }

        /** @return array<string, mixed>|null */
        public function redcap_module_link_check_display($project_id, $link) { return $link; }
    }

    class ExternalModules
    {
        /** @param array<mixed> $args @return mixed */
        public static function __callStatic(string $name, array $args) { return null; }
    }
}

namespace {

    /** REDCap-Core-Klassen mit statischen Methoden. */
    class REDCap { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class UserRights { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class Records { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class Logging { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class ToDoList { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class ActionTags { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class Survey { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class System { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }
    class Files { /** @param array<mixed> $a @return mixed */ public static function __callStatic(string $n, array $a) { return null; } }

    /** REDCap-Objekte: beliebige Methoden und Eigenschaften. */
    class Project
    {
        public function __construct($projectId = null) {}
        /** @param array<mixed> $a @return mixed */
        public function __call(string $n, array $a) { return null; }
        /** @return mixed */
        public function __get(string $n) { return null; }
    }

    class Message
    {
        /** @var mixed */
        public $ErrorInfo;
        public function __construct($projectId = null) {}
        /** @param array<mixed> $a @return mixed */
        public function __call(string $n, array $a) { return null; }
    }

    /** Von REDCap global definierte Funktionen. */
    function redirect(string $url): void {}

    /** Von REDCap je nach Seite definierte Konstanten (in phpstan.neon als dynamisch markiert). */
    if (!defined('APP_PATH_WEBROOT')) define('APP_PATH_WEBROOT', '');
    if (!defined('APP_PATH_DOCROOT')) define('APP_PATH_DOCROOT', '');
    if (!defined('EDOC_PATH')) define('EDOC_PATH', '');
    if (!defined('PAGE')) define('PAGE', '');
    if (!defined('PAGE_FULL')) define('PAGE_FULL', '');
    if (!defined('PROJECT_ID')) define('PROJECT_ID', 0);
    if (!defined('USERID')) define('USERID', '');
    if (!defined('SUPER_USER')) define('SUPER_USER', 0);
}
