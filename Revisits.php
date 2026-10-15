<?php
namespace UNILE\Revisits;

class Revisits extends \ExternalModules\AbstractExternalModule
{
    const MOVES_TABLE = 'revisits_moves';

    /**
     * REDCap builds module links absolutely from redcap_base_url, while its own links are relative.
     * On a server reachable under several host names (internal alias, external name, IP) the menu
     * link would move the user to the base URL's host, where their session cookie does not apply,
     * and REDCap asks for a new login. A root-relative link keeps the host the user is on.
     */
    public function redcap_module_link_check_display($project_id, $link)
    {
        $link = parent::redcap_module_link_check_display($project_id, $link);
        if (is_array($link) && isset($link['url'])) {
            $link['url'] = preg_replace('#^https?://[^/]+#i', '', $link['url']);
        }
        return $link;
    }

    /**
     * Other REDCap tables that store an instance number for record data. Each entry describes how
     * to reach a row's project, event and form; the form decides, for a repeating instrument,
     * whether the row belongs to the reordered form or to another one with its own numbering.
     * In these tables instance 1 is stored as 1 (in redcap_data it is NULL).
     */
    private static function relatedTables()
    {
        return array(
            'redcap_locking_data' => array(
                'join' => '', 'project' => 't.project_id', 'event' => 't.event_id', 'form' => 't.form_name'),
            'redcap_esignatures' => array(
                'join' => '', 'project' => 't.project_id', 'event' => 't.event_id', 'form' => 't.form_name'),
            'redcap_data_quality_status' => array(
                'join' => '', 'project' => 't.project_id', 'event' => 't.event_id',
                'form' => "coalesce(nullif(t.repeat_instrument, ''), (select fm.form_name from redcap_metadata fm
                            where fm.project_id = t.project_id and fm.field_name = t.field_name))"),
            'redcap_edocs_data_mapping' => array(
                'join' => '', 'project' => 't.project_id', 'event' => 't.event_id',
                'form' => "(select fm.form_name from redcap_metadata fm
                            where fm.project_id = t.project_id and fm.field_name = t.field_name)"),
            'redcap_surveys_response' => array(
                'join' => 'join redcap_surveys_participants p on p.participant_id = t.participant_id
                           join redcap_surveys s on s.survey_id = p.survey_id',
                'project' => 's.project_id', 'event' => 'p.event_id', 'form' => 's.form_name'),
            'redcap_surveys_pdf_archive' => array(
                'join' => 'left join redcap_surveys s on s.survey_id = t.survey_id',
                'project' => 'coalesce(t.project_id, s.project_id)', 'event' => 't.event_id', 'form' => 's.form_name'),
            'redcap_alerts_sent' => array(
                'join' => 'join redcap_alerts a on a.alert_id = t.alert_id',
                'project' => 'a.project_id', 'event' => 't.event_id', 'form' => "nullif(t.instrument, '')"),
            'redcap_alerts_recurrence' => array(
                'join' => 'join redcap_alerts a on a.alert_id = t.alert_id',
                'project' => 'a.project_id', 'event' => 't.event_id', 'form' => "nullif(t.instrument, '')"),
            'redcap_surveys_scheduler_queue' => array(
                'join' => 'join redcap_surveys_emails_recipients r on r.email_recip_id = t.email_recip_id
                           join redcap_surveys_participants p on p.participant_id = r.participant_id
                           join redcap_surveys s on s.survey_id = p.survey_id',
                'project' => 's.project_id', 'event' => 'p.event_id', 'form' => 's.form_name'),
            'redcap_outgoing_email_sms_log' => array(
                'join' => '', 'project' => 't.project_id', 'event' => 't.event_id', 'form' => "nullif(t.instrument, '')"),
        );
    }

    /**
     * Writes the planned moves into a temporary table, so that the data table and every related
     * table are renumbered from exactly the same mapping. Instances are stored as numbers, 1 for
     * the first instance. $moveDetails: "record\x1fevent_id" => [[old, new], ...].
     */
    public function createMovesTable($moveDetails, $moveFormNames)
    {
        $this->dropMovesTable();
        $this->query("create temporary table " . self::MOVES_TABLE . " (
                          record varchar(100) not null,
                          event_id int not null,
                          form_name varchar(100) null,
                          old_instance int not null,
                          new_instance int not null,
                          primary key (record, event_id, old_instance)
                      )", []);
        foreach ($moveDetails as $groupKey => $pairs) {
            list($record, $eventId) = explode("\x1f", $groupKey, 2);
            foreach ($pairs as $pair) {
                $this->query("insert into " . self::MOVES_TABLE . " (record, event_id, form_name, old_instance, new_instance)
                              values (?, ?, ?, ?, ?)",
                             [$record, $eventId, $moveFormNames[$groupKey], $pair[0], $pair[1]]);
            }
        }
    }

    public function dropMovesTable()
    {
        $this->query("drop temporary table if exists " . self::MOVES_TABLE, []);
    }

    const TX_MARKER_TABLE = 'revisits_tx_marker';

    /**
     * Starts the transaction for one date field.
     *
     * The framework retries a query that hit a deadlock (ExternalModules::query()), but by then
     * InnoDB has rolled back the whole transaction. With START TRANSACTION the retried query and
     * everything after it would run in autocommit mode and be written one by one - reproduced:
     * data rolled back, field history and invitations moved anyway. With autocommit off nothing
     * is written before COMMIT, and a marker row inserted first tells whether the transaction is
     * still the one that was started. Same for a lock wait timeout on servers with
     * innodb_rollback_on_timeout = ON.
     */
    public function beginTransaction()
    {
        $this->query("drop temporary table if exists " . self::TX_MARKER_TABLE, []);
        $this->query("create temporary table " . self::TX_MARKER_TABLE . " (n int not null) engine = InnoDB", []);
        $this->query("set autocommit = 0", []);
        $this->query("insert into " . self::TX_MARKER_TABLE . " (n) values (1)", []);
    }

    /**
     * Commits, unless the database has aborted the transaction in between. Checked with an UPDATE,
     * not a SELECT: REDCap may send SELECTs to a read replica, which does not know this
     * connection's temporary table.
     */
    public function commitTransaction()
    {
        if ($this->affectedRows("update " . self::TX_MARKER_TABLE . " set n = n + 1", []) !== 1) {
            throw new \Exception("The database aborted this run because the same records were being"
                               . " changed at the same time (deadlock). Please run the reordering again.");
        }
        $this->query("commit", []);
    }

    public function rollbackTransaction()
    {
        $this->query("rollback", []);
    }

    public function endTransaction()
    {
        $this->query("set autocommit = 1", []);
        $this->query("drop temporary table if exists " . self::TX_MARKER_TABLE, []);
    }

    private function affectedRows($sql, $params)
    {
        $query = $this->createQuery();
        $query->add($sql, $params);
        $query->execute();
        // Query::affected_rows asks the connection live - read it before the next query.
        return $query->affected_rows;
    }

    /**
     * Renumbers the data table. Two steps via negative interim numbers: a single UPDATE that sets
     * 1 -> 2 while 2 -> 1 is still pending would depend on the order in which rows are processed.
     * Returns the number of data rows moved.
     */
    public function moveData($pid, $dataTable)
    {
        $m = self::MOVES_TABLE;
        $moved = $this->affectedRows("
            update $dataTable d
            join $m m on d.project_id = ? and d.record = m.record and d.event_id = m.event_id
                     and coalesce(d.instance, 1) = m.old_instance
            join redcap_metadata md on md.project_id = d.project_id and md.field_name = d.field_name
            set d.instance = -m.new_instance
            where m.form_name is null or md.form_name = m.form_name
        ", [$pid]);
        $this->query("update $dataTable set instance = nullif(-instance, 1) where project_id = ? and instance < 0", [$pid]);
        return $moved;
    }

    /**
     * Renumbers the related tables with the same mapping, also in two steps - most of them have a
     * unique key over form/record/instance, so a direct swap would fail with "Duplicate entry".
     * Returns table => number of rows moved (only tables with at least one row).
     */
    public function moveRelatedTables($pid)
    {
        $m = self::MOVES_TABLE;
        $counts = array();
        foreach (self::relatedTables() as $table => $def) {
            $moved = $this->affectedRows("
                update $table t {$def['join']}
                join $m m on {$def['project']} = ? and t.record = m.record and {$def['event']} = m.event_id
                         and coalesce(t.instance, 1) = m.old_instance
                set t.instance = -m.new_instance
                where m.form_name is null or {$def['form']} = m.form_name
            ", [$pid]);
            if ($moved > 0) {
                $this->query("update $table t {$def['join']} set t.instance = -t.instance
                              where {$def['project']} = ? and t.instance < 0", [$pid]);
                $counts[$table] = $moved;
            }
        }
        return $counts;
    }

    /**
     * Plans the field-history rewrite. Read-only, so it can run before the transaction.
     *
     * The Logging page and the data history popup read redcap_log_event*, where the instance is a
     * text prefix of data_values ("[instance = N],\n", none for instance 1). All matching rows are
     * collected first and written afterwards; rewriting pair by pair would chain within a rotation
     * (a row moved 1 -> 2 picked up again by 2 -> 3, then by 3 -> 1).
     *
     * Only data-entry rows are touched (value changes and file uploads/deletions, which the popup
     * reads as well); the module's own "Reorder" entries describe the old numbering on purpose.
     * For a repeating event every form in the event shares one instance axis. For a repeating
     * instrument several independently numbered forms can live in the same event, so a row is only
     * rewritten if its fields belong to the reordered form.
     *
     * The record ID field (the project's first field) is not counted for that. REDCap stores one
     * record ID row per record, event and instance number and logs its creation as "Create record"
     * with nothing else in it. In a repeating instrument that row is shared by every form using the
     * same instance number and belongs to the first form, so it is not moved - and its log entry
     * stays as it is, matching the data. In a repeating event it moves with the instance, and so
     * does its entry. Deleting the whole record is not instance-specific and is never touched.
     *
     * Returns [log_event_id => new data_values, kept count, unattributable count].
     */
    public function planFieldHistory($pid, $moveDetails, $moveFormNames)
    {
        $logTable = \REDCap::getLogEventTable($pid);
        $fieldForms = array();
        $q = $this->query("select field_name, form_name from redcap_metadata where project_id = ? order by field_order", [$pid]);
        while ($row = $q->fetch_assoc()) {
            $fieldForms[$row['field_name']] = $row['form_name'];
        }
        $recordIdField = array_key_first($fieldForms);
        $updates = array();
        $kept = 0;
        $unattributable = 0;
        foreach ($moveDetails as $groupKey => $pairs) {
            list($moveRecord, $moveEventId) = explode("\x1f", $groupKey, 2);
            $scopeFormName = $moveFormNames[$groupKey];
            foreach ($pairs as $pair) {
                list($oldInstance, $newInstance) = $pair;
                $tagCondition = ($oldInstance === 1)
                    ? array("not like ?", "[instance = %")
                    : array("like ?", "[instance = $oldInstance],%");
                $rows = $this->query("
                    select log_event_id, event, description, data_values from $logTable
                    where project_id = ? and pk = ? and event_id = ?
                      and event in ('INSERT', 'UPDATE', 'DELETE', 'DOC_UPLOAD', 'DOC_DELETE')
                      and object_type like 'redcap\_data%'
                      and description <> 'Reorder repeating instances'
                      and data_values {$tagCondition[0]}
                ", [$pid, $moveRecord, $moveEventId, $tagCondition[1]]);
                while ($row = $rows->fetch_assoc()) {
                    $body = ($oldInstance === 1)
                        ? $row['data_values']
                        : substr($row['data_values'], strlen("[instance = $oldInstance],\n"));

                    // Checkbox values are logged as "field(code) = checked". A deleted file upload
                    // is logged as the bare field name, without "= value".
                    if ($row['event'] === 'DOC_DELETE') {
                        $loggedFields = array(trim($body));
                    } else {
                        preg_match_all('/^([a-z_][a-z0-9_]*)(?:\([^)]*\))?\s*=/mi', $body, $fieldMatches);
                        $loggedFields = array_unique($fieldMatches[1]);
                    }
                    $knownFields = array_intersect($loggedFields, array_keys($fieldForms));
                    $specificFields = array_diff($knownFields, array($recordIdField));
                    if (empty($specificFields)) {
                        if ($scopeFormName !== null || strpos($row['description'], 'Delete record') === 0) {
                            $kept++;
                            continue;
                        }
                    } elseif ($scopeFormName !== null) {
                        $forms = array_unique(array_map(function ($f) use ($fieldForms) {
                            return $fieldForms[$f];
                        }, $specificFields));
                        if (count($forms) > 1) {
                            $unattributable++;
                            continue;
                        }
                        if (reset($forms) !== $scopeFormName) {
                            continue;   // another repeating form with its own numbering
                        }
                    }

                    $newTag = ($newInstance === 1) ? "" : "[instance = $newInstance],\n";
                    $updates[$row['log_event_id']] = $newTag . $body;
                }
            }
        }
        return array($updates, $kept, $unattributable);
    }

    public function applyFieldHistory($pid, $updates)
    {
        $logTable = \REDCap::getLogEventTable($pid);
        foreach ($updates as $logEventId => $dataValues) {
            $this->query("update $logTable set data_values = ? where log_event_id = ?", [$dataValues, $logEventId]);
        }
    }
}
