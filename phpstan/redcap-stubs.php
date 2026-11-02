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
 * - Auch REDCap-Core-Klassen (REDCap, UserRights, Project, ...) deklarieren
 *   nur die tatsächlich genutzten Methoden und Eigenschaften (PHPStan wertet
 *   __call/__callStatic nicht als Freibrief). Rückgaben sind meist mixed:
 *   geprüft wird unser Code, nicht REDCaps Signaturen. Neue REDCap-Aufrufe
 *   hier ergänzen.
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
        public static function getREDCapProjectObject($projectId): \Project { return new \Project(); }
        public static function getUsername(): ?string { return null; }
        public static function isSuperUser(): bool { return false; }
        public static function isTemporaryRecordId($recordId): bool { return false; }
    }
}

namespace {

    /** REDCap-Core-Klassen: nur die tatsächlich genutzten statischen Methoden. */
    class REDCap
    {
        /** @return mixed */
        public static function getData(...$args) { return null; }
        /** @return mixed */
        public static function saveData(...$args) { return null; }
        /** @return mixed */
        public static function getDataDictionary(...$args) { return null; }
        /** @return mixed */
        public static function getEventNames(...$args) { return null; }
        /** @return mixed */
        public static function getLogEventTable(...$args) { return null; }
        /** @return mixed */
        public static function getRecordIdField(...$args) { return null; }
        /** @return mixed */
        public static function getSurveyLink(...$args) { return null; }
        /** @return mixed */
        public static function reserveNewRecordId(...$args) { return null; }
        /** @return mixed */
        public static function addFileToRepository(...$args) { return null; }
        /** @return mixed */
        public static function evaluateLogic(...$args) { return null; }
    }
    class UserRights
    {
        /** @return mixed */
        public static function getPrivileges(...$args) { return null; }
        /** @return mixed */
        public static function convertFormRightsToArray(...$args) { return null; }
        /** @return mixed */
        public static function hasDataViewingRights(...$args) { return null; }
    }
    class Records
    {
        /** @return mixed */
        public static function recordExists(...$args) { return null; }
        /** @return mixed */
        public static function getRecordGroupId(...$args) { return null; }
    }
    class Logging
    {
        /** @return mixed */
        public static function logEvent(...$args) { return null; }
    }
    class ToDoList
    {
        /** @return mixed */
        public static function insertAction(...$args) { return null; }
    }
    class ActionTags
    {
        /** @return mixed */
        public static function containsActionTags(...$args) { return null; }
    }
    class Survey
    {
        /** @return mixed */
        public static function getPageFields(...$args) { return null; }
    }

    /** REDCap-Projektobjekt: nur die genutzten Eigenschaften und Methoden. */
    class Project
    {
        /** @var array<string, array<string, mixed>> */
        public $metadata = [];
        /** @var array<string, array<string, mixed>> */
        public $forms = [];
        /** @var array<string, mixed> */
        public $surveys = [];
        /** @var array<int|string, array<int, string>> */
        public $eventsForms = [];
        /** @var array<int|string, array<string, mixed>> */
        public $eventInfo = [];
        /** @var bool */
        public $longitudinal = false;
        public function __construct($projectId = null) {}
        /** @return mixed */
        public function getRepeatingFormsEvents() { return []; }
        /** @return bool */
        public function isRepeatingForm($eventId, $form) { return false; }
        /** @return bool */
        public function isRepeatingEvent($eventId) { return false; }
        /** @return mixed */
        public function getUniqueEventNames($eventId = null) { return null; }
    }

    /** REDCap-Mailobjekt: nur die genutzten Methoden. */
    class Message
    {
        /** @var mixed */
        public $ErrorInfo;
        public function __construct($projectId = null) {}
        public function setTo($v): void {}
        public function setCc($v): void {}
        public function setBcc($v): void {}
        public function setFrom($v): void {}
        public function setFromName($v): void {}
        public function setSubject($v): void {}
        public function setBody($v): void {}
        public function setAttachment($path, $name = ''): void {}
        /**
         * Setzt bei Fehlern ErrorInfo.
         * @phpstan-impure
         */
        public function send(): bool { return true; }
    }

    /** Von REDCap global definierte Funktionen. */
    function redirect(string $url): void {}
    /** @return array<string, string> */
    function parseEnum($enum) { return []; }
    function label_decode($s): string { return ''; }

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
