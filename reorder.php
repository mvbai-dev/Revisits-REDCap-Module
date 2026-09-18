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
        echo "<form method=\"post\"><button>Re-order all visits based on $field_names_text</button></form>";
        echo <<<HTML
				<p><b>READ THIS:</b> This will try to re-order all repeatable events and repeatable instruments based 
				   on the field names above. Instruments/events which contain none of these fields should not be 
           affected. If a repeatable event contains several of these fields, the last one wins (but you should not rely on that).
					 <br>WARNING: This reordering does not show up in the Log and cannot be undone 
					 (apart from restoring a backup)!</p>
HTML;
    }
} else { // user clicked the button...
    $pid = $module->getProjectId();
    // REDCap spreads project data over several redcap_data* tables (sharding); the table
    // belonging to this project must be looked up at runtime instead of being hard-coded.
    // \REDCap::getDataTable() exists since REDCap 13.11; older versions only have one table.
    $dataTable = method_exists('\REDCap', 'getDataTable') ? \REDCap::getDataTable($pid) : 'redcap_data';
    $errors = array();
    $affected_rows = array();
    foreach ($module->getProjectSetting('visitdate-field') as $visitdateFieldName) {
       $sql = "
            update $dataTable d inner join
            (select rd.project_id, rd.record, rd.event_id, rd.instance, nullif(er.form_name, '') as form_name,
             nullif(row_number() over (partition by rd.record, rd.event_id order by rd.value),1) as new_instance
             from $dataTable rd, redcap_metadata md, redcap_events_repeat er where
               rd.project_id = ?
               and rd.field_name=md.field_name and md.project_id=rd.project_id
               and rd.field_name = ?
               and er.event_id=rd.event_id and (nullif(er.form_name, '') is null or er.form_name=md.form_name))
             new_instances
                on (d.project_id=new_instances.project_id and
                    d.record = new_instances.record and
                    d.event_id=new_instances.event_id and
                    coalesce(d.instance, -1)= coalesce(new_instances.instance, -1))
            inner join redcap_metadata md
            on (md.project_id=d.project_id and md.field_name=d.field_name)
              set d.instance=new_instances.new_instance where
                  coalesce(d.instance, -1) != coalesce(new_instances.new_instance, -1) and
                  (new_instances.form_name is null or new_instances.form_name=md.form_name)
       ";
       try {
           ExternalModules::query($sql, [$pid, $visitdateFieldName]);
           $affected_rows[] = $visitdateFieldName . ": " . db_affected_rows();
       } catch (\Throwable $e) {
           $errors[] = $visitdateFieldName . ": " . $e->getMessage();
       }
    }
    echo "<h2>Re-Order visits</h2>";
    echo "<p>Data table used for this project: <b>$dataTable</b></p>";
    if (!empty($affected_rows)) {
        echo "Numbers of reordered individual fields:<br>" . implode('<br>', $affected_rows);
    }
    if (!empty($errors)) {
        echo "<br><br>Sorry, some errors occured:<br>" . implode('<br>', $errors);
				echo "It's most likely that your MySQL/MariaDB version does not support window functions, " .
						 " whch are available since version 8.0";
    }
}

?>
