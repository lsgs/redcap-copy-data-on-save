# Copy Data on Save

Luke Stevens, Murdoch Children's Research Institute https://www.mcri.edu.au

[https://github.com/lsgs/redcap-copy-data-on-save](https://github.com/lsgs/redcap-copy-data-on-save)

## Description

This module enables data to be copied from one place to another when you save a form. You can set up multiple copy processes to different projects or even to other fields in the same project.

Copying the value of one field to multiple fields in the destination *is* supported. (Copying multiple fields to a single field is also supported but only the last value will be retained!)

Enable and configure the module in the source project. No action is required in the destination project.

### Summary of Copy Instructions

The module provides a summary page showing a table of the copy instruction settings along with (from v1.6.0) buttons that facilitate export and import of the instructions in CSV format. The summary page can be accessed via a link in the module description and in the configuration settings dialog.

## Repeating Data

Behaviour with repeating data is dependent on whether the field is repeating in the source, or in the destination, or both, as follows:

| Source        | Destination   | Behaviour              
| ------------- | ------------- | ---------------------- 
| Not repeating | Not repeating | To non-repeating field 
| Repeating     | Not repeating | To non-repeating field 
| Not repeating | Repeating     | New instance \* \*\*
| Repeating     | Repeating     | Same instance \*\*

\* Note that this will create a new instance *every time the rule is triggered*. Select the _"only if empty"_ option to create a new instance only when the value copied is different to the value in the current highest-numbered instance. This mode is like an audit trail - it gives a history of values for a field. 

\*\* You can override the default target event/form instance by writing an appropriate value (i.e. an integer 1-32767 or "new") to `redcap_repeat_instance`. This would typically be done using a `calc` field or `text` field with a `@CALCTEXT()` action tag to generate the desired instance number.

## File Fields

Copying for file fields is supported from v1.2.0 of the module. New versions of source field files will be created and saved to the destination field when a change in file name, type, or contents is detected.

## Enabling the Module with Existing Configurations

From v1.2.0, when you enable the module and existing configurations are present, such as following the creation of a project via copying another where the module is in use, certain configuration settings are automatically adjusted to reduce the risk of data being copied to an incorrect project. For example, it is not desirable for data to be copied back to a Production status project from a copy made of it for testing purposes.

For instructions that are enabled, and where the destination project is different to the source project: 
* *Enabled?* will be set to `false`, and
* *Destination project* will be deleted (set to empty)

## Limitations

* Data may be copied *from* fields only in the same event as the triggering form.
* Data may be copied *to* fields in a single event. To copy to multiple events set up a trigger/rule for each destination event.
* Copying data occurs when a data entry form or survey page is saved. Copying is not triggered for data imports.

## Configuration

**Name / Description (Optional)**
* Optionally provide a name/reference and description for each copy instruction.

**Enabled?**
* Check to enable the copy process. Uncheck to disable.

**Trigger form(s)**
* One or more instruments for which the current copy process will be triggered.

**Trigger condition**
* *Optional*: a REDCap logic expression that must evaluate to *true* for the current ercord in order for the copty process to be executed. Leave empty to always copy on saving the trigger form(s).
	
**Destination project**
* The project to copy data *to*. Can be within the current project e.g. copying data to other events or fields.
	
**Destination event name**
* *Optional*: the unique event name of the event to copy data to in the destination. Alternatively, a field in the source project (within the trigger context) that will contain the unique event name to use. Leave empty if the destination is not longitudinal or to copy to the first event.

**Field containing destination record id**
* Field containing record id for destination project. The value from this field is used to locate the corresponding record in the destination project using the mechanism specified below.
    
**Record matching option**
* Options for locating a matched record in the destination project and for whether to create new 
    0. Match record id (do not create): Copy to record id saved to the field specified above. If no matching record found then do nothing.
    1. Match record id (create matching): Copy to record id saved to the field specified above. If no matching record found then create one with matching record id.
    2. Match record id (create auto-numbered): Copy to record id saved to the field specified above. If no value is present in the field then create an auto-numbered record in the destination and save the created record id to the field specified above. If a value is present and does not match a record in the destination, do nothing.
    3. Look up via secondary unique field: Find a record with the value from the field specified above in the secondary unique field of the destination project. If no match is found, do nothing. (Note: it is assumed that the secondary unique field is present in the first event.)

**Data Access Group option**
* Select how the *current* DAG of the copied record should be utilised.
    1. Ignore or N/A: do not set or update the DAG for the record in the destination project.
    2. Include DAG in copy: the unique DAG name for the source record's DAG will be included in the copy. Destination DAGs must have matching names for the copy to be successful.
    3. Map source DAGs to destination DAGs [deprecated]
        * This option is deprecated and should no longer be selected (although previous configurations using this option will still function).
        * Instead, select the *"Ignore or N/A"* option and specify `redcap_data_access_group` as a destination field populated with a unique group name copied from a `@CALCTEXT` or suitably coded dropdown or radio field.
        * The gif below shows an example of setting a record's DAG from a `@CALCTEXT` result.

**Copy fields**
* Pairs of fields mapping the source to the destination.
* Select the "only if empty" checkbox if you want the copy to occur only for an initial value in the source field (i.e. copy the value only when the destination field is empty).
* Note 1: you can update the destination record's DAG by writing to `redcap_data_access_group` as the destination field. 
* Note 2: you can update data in a specific repeating event/form instance by writing an appropriate value (i.e. an integer 1-32767 or "new") to `redcap_repeat_instance`. 
* Note 3: Ensure the destination fields are specified using the field name only - no square brackets or other extraneous characters.

### Failure Alert

Optionally specify one or more email addresses to be alerted if a copy fails, for example due to field type or value incompatibility.

## Example

This example illustrates a few things that the module facilitates:
* Copying data between fields in the same project
* Setting the Form Status automatically (in this case using a radio field, but using a calc field is an alternative).
* Setting the record's DAG automatically by using a text field with @CALCTEXT() to generate a valid unique DAG name.
`@CALCTEXT(if([record-name] - rounddown(([record-name]/2),0)*2=0,'even','odd'))`

![Copy on save](./set_status_set_dag.gif) 
![Copy on save config](./set_status_set_dag_config.png)