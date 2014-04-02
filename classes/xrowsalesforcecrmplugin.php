<?php

class xrowSalesForceCRMPlugin implements xrowFormCRM
{
    static private $connection = null;
    static $fields = null;

    public function getCampaigns()
    {
        try
        {
            $connection = self::getConnection();
            $query = "SELECT Id, Name FROM Campaign";
            $response = $connection->query( $query );
            $campaignArray = array();
            if( isset( $response->records ) && is_array( $response->records ) && count( $response->records ) > 0 )
            {
                foreach( $response->records as $key => $record ) 
                {
                    $campaignArray[$record->Id] = $record->Name;
                }
            }
            return $campaignArray;
        }
        catch( Exception $e )
        {
            throw new xrowSalesForceException( $e->getMessage() . ' -> xrowSalesForceCRMPlugin::getCampaigns' );
        }
    }

    public function getFields()
    {
        if( self::$fields === null )
        {
            try
            {
                $connection = self::getConnection();
                $fieldsArray = array();
                $sObject = $connection->describeSObjects( array( 'Lead' ) );
                if( isset( $sObject[0] ) )
                {
                    $sObject = $sObject[0];
                    $fieldsArray = array();
                    if( isset( $sObject ) && isset( $sObject->fields ) && is_array( $sObject->fields ) && count( $sObject->fields ) > 0 )
                    {
                        $salesforceini = eZINI::instance('salesforce.ini');
                        if( $salesforceini->hasVariable( 'Settings', 'LoadFieldsInFormGenerator' ) )
                        {
                            $showOnlyFields = $salesforceini->variable( 'Settings', 'LoadFieldsInFormGenerator' );
                            foreach( $sObject->fields as $field ) 
                            {
                                if( $field->deprecatedAndHidden === false && in_array( $field->name, $showOnlyFields ) )
                                {
                                    $field = (array)$field;
                                    if( $field['type'] == 'picklist' )
                                    {
                                        $tmpPicklist = $field['picklistValues'];
                                        unset( $field['picklistValues'] );
                                        foreach( $tmpPicklist as $picklistValue )
                                        {
                                            $field['picklistValues'][] = (array)$picklistValue;
                                        }
                                    }
                                    $fieldsArray[] = (array)$field;
                                }
                            }
                        }
                        else
                        {
                            foreach( $sObject->fields as $field ) 
                            {
                                if( $field->deprecatedAndHidden === false )
                                {
                                    $fieldsArray[] = (array)$field;
                                }
                            }
                        }
                    }
                    usort( $fieldsArray, array( "xrowSalesForceCRMPlugin", "cmp" ) );
                }
                self::$fields = $fieldsArray;
            }
            catch( Exception $e )
            {
                throw new xrowSalesForceException( $e->getMessage() . ' -> xrowSalesForceCRMPlugin::getFields' );
            }
        }
        return self::$fields;
    }

    public function setAttributeDataForCRMField( $data, $http, $id, $crm )
    {
        if( $crm )
        {
            $data['label'] = $crm;
        }
        if( strpos( $data['type'], 'boolean' ) !== false )
        {
            if( $data['def'] !== null )
            {
                $data['def'] = true;
            }
            else
            {
                $data['def'] = false;
            }
        }
        if( strpos( $data['type'], 'picklist' ) !== false )
        {
            $data['option_array'] = array();
            if( $http->hasPostVariable( 'XrowFormElementCRMField' . $id . $data['name'] ) )
            {
                $pickList = $http->postVariable( 'XrowFormElementCRMField' . $id . $data['name'] );
                $options = array();
                foreach ( $pickList as $optKey => $pickListValue )
                {
                    $explodePickListValue = explode( ':', $pickListValue );
                    $item = array( 'value' => $explodePickListValue[0], 'name' => $explodePickListValue[1], 'def' => false );
                    if( $http->hasPostVariable( 'XrowFormElementCRMField' . $id . $data['name'] . 'Default' ) )
                    {
                        $crmFieldValueDefault = $http->postVariable( 'XrowFormElementCRMField' . $id . $data['name'] . 'Default' );
                        if( $crmFieldValueDefault == $explodePickListValue[0] )
                        {
                            $item['def'] = true;
                        }
                    }
                    $options[$optKey] = $item;
                }
                $data['option_array'] = $options;
            }
        }
        return $data;
    }

    public function setAttributeDataForCollectCRMField( $content, $key, $item, $inputContentCollection, $contentobject_id )
    {
        switch ( $item['type'] )
        {
            case "crmfield:string":
            case "crmfield:textarea":
            {
                $data = '';
                if( isset( $inputContentCollection[$item['name']] ) )
                {
                    $data = trim( $inputContentCollection[$item['name']] );
                }
                if ( $item['req'] == true && trim( $data ) == '' )
                {
                    $content['form_elements'][$key]['error'] = true;
                    $content['has_error'] = true;
                    $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Input required." );
                }
                $content['form_elements'][$key]['def'] = $data;
            }break;
            case "crmfield:email":
            {
                $data = '';
                if( isset( $inputContentCollection[$item['name']] ) )
                {
                    $data = trim( $inputContentCollection[$item['name']] );
                }
                if ( $item['req'] == true )
                {
                    if ( $data == '' )
                    {
                        $content['form_elements'][$key]['error'] = true;
                        $content['has_error'] = true;
                        $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Input required." );
                    }
                    elseif( $item['val'] == true )
                    {
                        if ( !xrowFormGeneratorType::validate( $data ) )
                        {
                            $content['form_elements'][$key]['error'] = true;
                            $content['has_error'] = true;
                            $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "E-mail address is not valid." );
                        }
                        elseif( $item['unique'] == true )
                        {
                            if ( !xrowFormGeneratorType::email_unique( $data, $contentobject_id ) )
                            {
                                $content['form_elements'][$key]['error'] = true;
                                $content['has_error'] = true;
                                $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Your email was already submitted to us. You can't use the form twice." );
                            }       
                        }
                    }    
                }
                elseif ( $item['val'] == true && $data != '' )
                {
                    if ( !xrowFormGeneratorType::validate( $data ) )
                    {
                        $content['form_elements'][$key]['error'] = true;
                        $content['has_error'] = true;
                        $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "E-mail address is not valid." );
                    }
                    elseif( $item['unique'] == true ) 
                    {
                        if ( !xrowFormGeneratorType::email_unique( $data, $contentobject_id ) )
                        {
                            $content['form_elements'][$key]['error'] = true;
                            $content['has_error'] = true;
                            $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Your email was already submitted to us. You can't use the form twice." );
                        }                             
                    }
                }
                elseif( $item['unique'] == true && $data != '' )
                {
                    if ( !xrowFormGeneratorType::email_unique( $data, $contentobject_id ) )
                    {
                        $content['form_elements'][$key]['error'] = true;
                        $content['has_error'] = true;
                        $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Your email was already submitted to us. You can't use the form twice." );
                    }   
                }
                $content['form_elements'][$key]['def'] = $data;
            }break;
            case "crmfield:boolean":
            {
                $data = false;
                if( isset( $inputContentCollection[$item['name']] ) )
                {
                    $data = true;
                }
                if ( $item['req'] == true )
                {
                    if ( !$data )
                    {
                        $content['form_elements'][$key]['error'] = true;
                        $content['has_error'] = true;
                        $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "You need to select this checkbox." );
                    }
                }
                $content['form_elements'][$key]['def'] = $data;
            }break;
            case "crmfield:phone":
            {
                $data = '';
                $checkTelephone = false;
                if( isset( $inputContentCollection[$item['name']] ) )
                {
                    $data = trim( $inputContentCollection[$item['name']] );
                }
                if ( $item['req'] == true )
                {
                    if ( $data == '' )
                    {
                        $content['form_elements'][$key]['error'] = true;
                        $content['has_error'] = true;
                        $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Input required." );
                    }
                    else
                    {
                        $checkTelephone = true;
                    }
                }
                
                if( $checkTelephone )
                {
                    if( !xrowFormGeneratorType::telephone_validate( $data ) || strlen( $data ) >= 25 )
                    {
                        $content['form_elements'][$key]['error'] = true;
                        $content['has_error'] = true;
                        $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Please enter a valid phone number." );
                    }
                }
                $content['form_elements'][$key]['def'] = $data;
            }break;
            case "crmfield:picklist":
            {
                if( isset( $inputContentCollection[$item['name']] ) )
                {
                    $data = $inputContentCollection[$item['name']];
                    $optSelected = false;
                    
                    foreach ( $item['option_array'] as $optKey => $optItem )
                    {
                        $content['form_elements'][$key]['option_array'][$optKey]['def'] = false;
                        if ( $optItem['name'] == $data )
                        {
                            $content['form_elements'][$key]['option_array'][$optKey]['def'] = true;
                            $optSelected = true;
                        }
                    }
                    if ( $item['req'] == true )
                    {
                        if ( !$optSelected )
                        {
                            $content['form_elements'][$key]['error'] = true;
                            $content['has_error'] = true;
                            $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Please select at least one option." );
                        }
                    }
                }
                else
                {
                    if ( $item['req'] == true )
                    {
                        $content['form_elements'][$key]['error'] = true;
                        $content['has_error'] = true;
                        $content['error_array'][] = $item['label'] . ": " . ezpI18n::tr( 'kernel/classes/datatypes', "Please select at least one option." );
                    }
                }
            }break;
        }
        return $content;
    }

    public static function sendExportData( $objectAttribute )
    {
        // export only if campaign ID is set
        if( isset( $objectAttribute->Content['campaign_id'] ) && (int)$objectAttribute->Content['campaign_id'] > 0 && $objectAttribute->Content['campaign_id'] != '' )
        {
            $campaign_id = $objectAttribute->Content['campaign_id'];
            if( isset( $objectAttribute->Content['form_elements'] ) )
            {
                $form_elements = $objectAttribute->Content['form_elements'];
                $lead = new stdClass;
                $foundCRMField = false;
                $ini = eZINI::instance( 'salesforce.ini' );
                foreach( $form_elements as $item )
                {
                    switch ( $item['type'] )
                    {
                        case "crmfield:string":
                        case "crmfield:phone":
                        case "crmfield:email":
                        case "crmfield:boolean":
                        case "crmfield:textarea":
                        {
                            $name = $item['name'];
                            $lead->$name = $item['def'];
                            $foundCRMField = true;
                        }break;
                        case "crmfield:picklist":
                        {
                            foreach ( $item['option_array'] as $optKey => $optItem )
                            {
                                if ( $optItem['def'] )
                                {
                                    $name = $item['name'];
                                    $lead->$name = $optItem['value'];
                                    $foundCRMField = true;
                                    break;
                                }
                            }
                        }break;
                    }
                }
                if( $foundCRMField && !isset( $lead->Company ) )
                {
                    // Company ist ein Pflichtfeld. Soll hier nachträglich gesetzt werden, falls es noch nicht gefüllt wurde
                    $lead->Company = 'nicht angegeben';
                }
                if( $foundCRMField )
                {
                    $result = self::saveStandardObjectData( $lead, 'Lead', 'create' );
                    if( $result->success !== false )
                    {
                        $leadmember = new stdClass;
                        $leadmember->CampaignId = $campaign_id;
                        $leadmember->LeadId = $result->id;
                        $result_member = self::saveStandardObjectData( $leadmember, 'CampaignMember', 'create' );
                        if( $result_member->success === false )
                        {
                            return false;
                        }
                    }
                    else
                    {
                        return false;
                    }
                }
            }
        }
    }

    public static function saveStandardObjectData( $StandardObject, $class, $type )
    {
        if( $StandardObject && $class != '' && ( $type == 'create' || $type == 'update' ) )
        {
            $ini = eZINI::instance( 'salesforce.ini' );
            $exportFieldIntoField = array();
            if( $ini->hasVariable( 'Settings', 'ExportFieldIntoField' ) )
            {
                $exportFieldIntoField = $ini->variable( 'Settings', 'ExportFieldIntoField' );
                foreach( $StandardObject as $StandardObjectItemName => $StandardObjectItemValue )
                {
                    if( isset( $exportFieldIntoField[$StandardObjectItemName] ) )
                    {
                        $value = $StandardObjectItemValue;
                        unset( $StandardObject->$StandardObjectItemName );
                        $StandardObject->$exportFieldIntoField[$StandardObjectItemName] = $value;
                    }
                }
            }
            if( $ini->hasVariable( 'UTMSettings', 'SaveInClass' ) )
            {
                $SaveInClass = $ini->variable( 'UTMSettings', 'SaveInClass' );
                if( $SaveInClass == $class )
                {
                    if( $ini->hasVariable( 'UTMSettings_' . $SaveInClass, 'SaveFields' ) )
                    {
                        $utmData = new GA_Parse( $_COOKIE );
                        $utmSaveFieldIntoFields = $ini->variable( 'UTMSettings_' . $SaveInClass, 'SaveFields' );
                        foreach( $utmSaveFieldIntoFields as $utmSaveFieldIntoFieldKey => $utmSaveFieldIntoField )
                        {
                            if( isset( $utmData->$utmSaveFieldIntoFieldKey ) && $utmData->$utmSaveFieldIntoFieldKey !== null )
                            {
                                $StandardObject->$utmSaveFieldIntoField = $utmData->$utmSaveFieldIntoFieldKey;
                            }
                        }
                    }
                }
            }
            $resultError = new stdClass;
            try
            {
                $connection = self::getConnection();
                $result = $connection->$type( array( $StandardObject ), $class );
                if( is_array( $result ) && isset( $result[0] ) )
                {
                    $resultItem = $result[0];
                    if( isset( $resultItem->errors ) && count( $resultItem->errors ) > 0 )
                    {
                        $resultItem->errors = $resultItem->errors[0]->message;
                        eZDebug::writeError( $resultItem->errors, 'xrowSalesForceCRMPlugin::saveStandardObjectData::' . $class . '::' . $type );
                    }
                    return $resultItem;
                }
                else
                {
                    eZDebug::writeError( 'result is not set', 'xrowSalesForceCRMPlugin::saveStandardObjectData::' . $class . '::' . $type );
                    $resultError->errors = 'result is not set for class ' . $class . ' function ' . $type;
                    return $resultError;
                }
            }
            catch( Exception $e )
            {
                eZDebug::writeError( $e->getMessage(), 'xrowSalesForceCRMPlugin::saveStandardObjectData' );
                $resultError->errors = $e->getMessage();
                return $resultError;
            }
        }
    }

    static public function executeQuery( $query )
    {
        try
        {
            $connection = self::getConnection();
            //$query = "SELECT Id, Name FROM Campaign WHERE Id = '" . $campaignID . "'";
            $response = $connection->query( $query );
            if( isset( $response->records ) && is_array( $response->records ) && count( $response->records ) > 0 )
            {
                return $response->records;
            }
            elseif( isset( $response->records ) && is_array( $response->records ) && count( $response->records ) == 0 )
            {
                return false;
            }
        }
        catch( Exception $e )
        {
            throw new xrowSalesForceException( $e->getMessage() );
        }
    }

    static private function getConnection()
    {
        if( self::$connection === null )
        {
            try
            {
                self::$connection = Salesforce::factory();
            }
            catch( Exception $e )
            {
                throw new xrowSalesForceException( $e->getMessage() . ' -> xrowSalesForceCRMPlugin::getConnection' );
            }
        }
        return self::$connection;
    }

    static function cmp( $a, $b )
    {
        $al = strtolower($a['label']);
        $bl = strtolower($b['label']);
        if ($al == $bl) {
            return 0;
        }
        return ($al > $bl) ? +1 : -1;
    }
}