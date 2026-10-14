<?php

namespace UNILE\Revisits;

use ExternalModules\ExternalModules;

if($_SERVER['REQUEST_METHOD'] != 'POST'){ // show form as a simple button
    $field_names = $module->getProjectSetting('visitdate-field');
    $field_names_text = implode(',', $field_names);
    echo "<h2>Re-Order visits</h2>";
    if (!$field_names_text) {
        echo "Please configure at least one visit date field in the module configuration first!";
    } else {
        echo "<form method=\"post\">"
           . "<input type=\"hidden\" name=\"redcap_csrf_token\" value=\"" . $module->getCSRFToken() . "\">"
           . "<button>Re-order all visits based on $field_names_text</button></form>";
        echo <<<HTML
				<p><b>READ THIS:</b> This will try to re-order all repeatable events and repeatable instruments based 
				   on the field names above. Instruments/events which contain none of these fields should not be 
           affected. If a repeatable event contains several of these fields, the last one wins (but you should not rely on that).
					 <br>Instances with no value in the date field are sorted to the end, keeping their relative order.
					 <br>The reordering is written to the project Logging page (one entry per record and event),
					 but it still cannot be undone automatically - restoring a backup is the only way back!
					 <br>Existing field history entries of the moved instances are updated to the new instance
					 numbers (see README).</p>
HTML;
    }
} else { // user clicked the button...
    $pid = $module->getProjectId();
    // REDCap spreads project data over several redcap_data* tables (sharding); the table
    // belonging to this project must be looked up at runtime instead of being hard-coded.
    $dataTable = $module->getDataTable($pid);
    $errors = array();
    $affected_rows = array();
    $blank_notices = array();
    $log_notices = array();
    foreach ($module->getProjectSetting('visitdate-field') as $visitdateFieldName) {
       // Only date and datetime fields may be used. REDCap stores those as YYYY-MM-DD[ HH:MM[:SS]]
       // regardless of the display format, so the plain string sort below is chronological.
       // For any other field type the sort would be lexical and therefore meaningless
       // ('10' < '2' < '9'). The config.json field-list is filtered to date fields as well,
       // but a setting stored before that filter existed would not be re-validated.
       $q = $module->query("select element_validation_type from redcap_metadata
                            where project_id = ? and field_name = ?", [$pid, $visitdateFieldName]);
       $validationType = $q->fetch_row()[0] ?? null;
       if ($validationType === null) {
           $errors[] = "$visitdateFieldName: field does not exist in this project - skipped";
           continue;
       }
       if (strpos($validationType, 'date') !== 0) {
           $errors[] = "$visitdateFieldName: not a date or datetime field"
                     . " (validation type '" . ($validationType === '' ? 'none' : $validationType) . "') - skipped";
           continue;
       }

       // All instances that actually exist in the repeating group the date field belongs to,
       // with the date value attached via LEFT JOIN. Enumerating the instances from the date
       // field itself (as this module used to do) silently skips instances where the date is
       // empty - those would keep their old number and collide with a renumbered instance.
       // The repeating group is either a repeating instrument (er.form_name = the date
       // field's form) or a whole repeating event (er.form_name empty; redcap_events_forms
       // is only populated for longitudinal projects, so it may only be used in that branch).
       $scope_sql = "
            select inst.record, inst.event_id, inst.instance, inst.form_name, dv.value as visitdate
            from (
                select distinct rd.record, rd.event_id, rd.instance, nullif(er.form_name, '') as form_name
                from $dataTable rd
                join redcap_metadata md  on md.project_id = rd.project_id and md.field_name = rd.field_name
                join redcap_metadata dfm on dfm.project_id = rd.project_id and dfm.field_name = ?
                join redcap_events_repeat er on er.event_id = rd.event_id
                     and (
                          (nullif(er.form_name, '') is not null
                           and er.form_name = dfm.form_name and md.form_name = er.form_name)
                       or (nullif(er.form_name, '') is null
                           and exists (select 1 from redcap_events_forms ef
                                       where ef.event_id = er.event_id and ef.form_name = dfm.form_name))
                     )
                where rd.project_id = ?
            ) inst
            left join $dataTable dv
                   on dv.project_id = ? and dv.record = inst.record and dv.event_id = inst.event_id
                  and coalesce(dv.instance, -1) = coalesce(inst.instance, -1) and dv.field_name = ?
       ";
       $scope_params = [$visitdateFieldName, $pid, $pid, $visitdateFieldName];

       // The new numbering.
       $numbered_sql = "
            select s.record, s.event_id, s.instance, s.form_name,
             nullif(row_number() over (partition by s.record, s.event_id
                    order by (s.visitdate is null or s.visitdate = ''), s.visitdate,
                             coalesce(s.instance, 1)), 1) as new_instance
            from ($scope_sql) s
       ";

       try {
           // Report how many instances are being sorted to the end for lack of a date.
           $q = $module->query("select count(*) from ($scope_sql) s
                                where s.visitdate is null or s.visitdate = ''", $scope_params);
           $blanks = (int) $q->fetch_row()[0];
           if ($blanks > 0) {
               $blank_notices[] = "$visitdateFieldName: $blanks instance(s) without a date value were sorted to the end";
           }

           // Everything that is read happens before the first write: REDCap may send plain SELECTs
           // to a read replica, which would not see this run's uncommitted changes.
           //
           // What is about to change, grouped by record and event. Computed once; the data table,
           // the related tables, the project log and the field history all use this same mapping.
           $moves = array();
           $moveDetails = array();
           $moveFormNames = array();
           $q = $module->query("
                select n.record, n.event_id, n.form_name,
                       coalesce(n.instance, 1) as old_instance,
                       coalesce(n.new_instance, 1) as new_instance
                from ($numbered_sql) n
                where coalesce(n.instance, -1) != coalesce(n.new_instance, -1)
                order by n.record, old_instance
           ", $scope_params);
           while ($row = $q->fetch_assoc()) {
               $groupKey = $row['record'] . "\x1f" . $row['event_id'];
               $moves[$groupKey][] = $row['old_instance'] . " -> " . $row['new_instance'];
               $moveDetails[$groupKey][] = [(int) $row['old_instance'], (int) $row['new_instance']];
               $moveFormNames[$groupKey] = $row['form_name'];
           }
           list($historyUpdates, $historyKept, $historyUnattributable) =
               $module->planFieldHistory($pid, $moveDetails, $moveFormNames);

           $rowsAffected = 0;
           $relatedCounts = array();
           if (!empty($moveDetails)) {
               // One transaction per date field: data, related tables, project log and field
               // history are renumbered together or not at all.
               $module->createMovesTable($moveDetails, $moveFormNames);
               $module->query("start transaction", []);
               try {
                   $rowsAffected = $module->moveData($pid, $dataTable);
                   $relatedCounts = $module->moveRelatedTables($pid);

                   // Project log (redcap_log_event*): one entry per record and event, so the
                   // reordering shows up under Logging and in each record's own history.
                   foreach ($moves as $groupKey => $instanceMoves) {
                       list($record, $eventId) = explode("\x1f", $groupKey, 2);
                       \Logging::logEvent(
                           "",                                   // sql_log - no raw SQL, it would be identical for every record
                           $dataTable,                           // object_type
                           "UPDATE",                             // event
                           $record,                              // pk
                           "Repeating instances reordered by '$visitdateFieldName':\n"
                               . implode(", ", $instanceMoves),  // data_values
                           "Reorder repeating instances",        // description (column is varchar(100))
                           "",                                   // change_reason
                           "",                                   // userid_override - keep the acting user
                           $pid,                                 // project_id_override
                           true,                                 // useNOW
                           $eventId                              // event_id_override
                       );
                   }

                   $module->applyFieldHistory($pid, $historyUpdates);
                   $module->query("commit", []);
               } catch (\Throwable $e) {
                   $module->query("rollback", []);
                   throw $e;
               } finally {
                   $module->dropMovesTable();
               }
           }

           $affected_rows[] = $visitdateFieldName . ": " . $rowsAffected;
           $log_notices[] = $visitdateFieldName . ": " . count($moves)
                          . " record/event group(s) written to the project log";
           if (!empty($historyUpdates) || $historyUnattributable > 0) {
               $log_notices[] = $visitdateFieldName . ": field history - " . count($historyUpdates)
                              . " log entry/entries updated to the new instance numbers"
                              . ($historyUnattributable > 0
                                    ? ", $historyUnattributable left unchanged (fields of several forms,"
                                      . " cannot be attributed to one repeating form)"
                                    : "");
           }
           if (!empty($relatedCounts)) {
               $parts = array();
               foreach ($relatedCounts as $table => $n) $parts[] = "$table: $n";
               $log_notices[] = $visitdateFieldName . ": also renumbered - " . implode(", ", $parts);
           }

           // Module log: one entry per run and field, for technical traceability.
           $module->log("Reorder repeating instances", array(
               "field"                   => $visitdateFieldName,
               "data_table"              => $dataTable,
               "groups_changed"          => count($moves),
               "rows_affected"           => $rowsAffected,
               "instances_without_date"  => $blanks,
               "history_rows_rewritten"  => count($historyUpdates),
               "history_rows_kept_record_id" => $historyKept,
               "history_rows_unattributable" => $historyUnattributable,
               "related_tables"          => json_encode($relatedCounts),
           ));
       } catch (\Throwable $e) {
           $errors[] = $visitdateFieldName . ": " . $e->getMessage()
                     . " - nothing was changed for this field"
                     . " (if this is a SQL error, check that your MySQL/MariaDB version supports"
                     . " window functions - MySQL 8.0 / MariaDB 10.2 and above)";
       }
    }
    echo "<h2>Re-Order visits</h2>";
    echo "<p>Data table used for this project: <b>$dataTable</b></p>";
    if (!empty($affected_rows)) {
        echo "Numbers of reordered individual fields:<br>" . implode('<br>', $affected_rows);
    }
    if (!empty($log_notices)) {
        echo "<br><br>" . implode('<br>', $log_notices);
    }
    if (!empty($blank_notices)) {
        echo "<br><br>" . implode('<br>', $blank_notices);
    }
    if (!empty($errors)) {
        echo "<br><br>Sorry, some errors occured:<br>" . implode('<br>', $errors);
    }
}

?>
