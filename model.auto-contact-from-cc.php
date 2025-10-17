<?php
// iTop Extension: Auto create contact from CC in Mail to Ticket Automation
// Compatible with iTop 3.1 - FIXED VERSION

if (!class_exists('AutoContactFromCCExtension'))
{
    class AutoContactFromCCExtension implements iApplicationObjectExtension
    {
        // Centralized logging
        protected function LogMessage($sLevel, $sMessage)
        {
            try {
                if (strtoupper($sLevel) === 'INFO') {
                    IssueLog::Info($sMessage);
                } else {
                    IssueLog::Trace($sMessage);
                }
            } catch (Exception $e) {
                // ignore
            }

            try {
                $sLogDir = defined('APPROOT') ? APPROOT . 'log' . DIRECTORY_SEPARATOR : __DIR__ . DIRECTORY_SEPARATOR;
                $sLogFile = $sLogDir . 'auto-contact-from-cc.log';
                $sLine = date('Y-m-d H:i:s') . " [" . strtoupper($sLevel) . "] " . $sMessage . PHP_EOL;
                file_put_contents($sLogFile, $sLine, FILE_APPEND | LOCK_EX);
            } catch (Exception $e) {
                // ignore
            }
        }

        // FIX 1: Gunakan AfterInsertDB instead of OnDBInsert
        // Ini dipanggil SETELAH ticket benar-benar masuk ke DB
        public function AfterInsertDB($oObject, $oContextArgs = array())
        {
            if ($oObject instanceof UserRequest || $oObject instanceof Incident)
            {
                $this->LogMessage('info', "AfterInsertDB triggered for ticket " . $oObject->GetKey());
                
                // FIX 2: Reload object dari DB untuk dapat data terbaru
                $oObject->Reload();
                
                $sHeaders = $oObject->Get('origin_email_headers');
                $this->LogMessage('trace', "Headers content: " . substr($sHeaders, 0, 200) . "...");
                
                if (!empty($sHeaders))
                {
                    $this->ProcessCC($oObject, $sHeaders);
                }
                else
                {
                    $this->LogMessage('info', "No headers found for ticket " . $oObject->GetKey());
                }
            }
        }

        public function OnDBInsert($oObject, $oContextArgs = array()) {}
        public function OnDBUpdate($oObject, $oChanges = null, $oContextArgs = array()) {}
        public function OnDBDelete($oObject, $oContextArgs = array()) {}
        public function OnDBClone($oObject, $oNewObj) {}
        public function OnIsModified($oObject) {}
        public function OnCheckToWrite($oObject) {}
        public function OnCheckToDelete($oObject) {}
        public function OnCheckToClone($oObject, $oNewObj) {}
        public function AfterUpdateDB($oObject, $oChanges = null, $oContextArgs = array()) {}
        public function AfterDeleteDB($oObject, $oContextArgs = array()) {}
        public function AfterCloneDB($oObject, $oNewObj) {}

        protected function ProcessCC($oTicket, $sHeaders)
        {
            $aCcLines = array();
            $sCurrent = '';
            $lines = preg_split("/\r?\n/", $sHeaders);
            $collect = false;
            
            foreach ($lines as $line)
            {
                if (preg_match('/^\s*Cc:\s*(.*)/i', $line, $m))
                {
                    if ($collect && $sCurrent !== '')
                    {
                        $aCcLines[] = $sCurrent;
                    }
                    $sCurrent = $m[1];
                    $collect = true;
                }
                elseif ($collect && preg_match('/^\s+(.*)/', $line, $m))
                {
                    $sCurrent .= ' ' . $m[1];
                }
                else
                {
                    if ($collect && $sCurrent !== '')
                    {
                        $aCcLines[] = $sCurrent;
                        $sCurrent = '';
                    }
                    $collect = false;
                }
            }
            if ($collect && $sCurrent !== '')
            {
                $aCcLines[] = $sCurrent;
            }

            $this->LogMessage('info', "Found " . count($aCcLines) . " Cc line(s) for ticket " . $oTicket->GetKey());

            foreach ($aCcLines as $sCcLine)
            {
                $sCcLine = trim($sCcLine);
                if ($sCcLine === '') continue;

                $this->LogMessage('info', "Processing Cc line: {$sCcLine}");

                $aEmails = array();
                if (function_exists('imap_rfc822_parse_adrlist'))
                {
                    $aParsed = imap_rfc822_parse_adrlist($sCcLine, '');
                    foreach ($aParsed as $oEmail)
                    {
                        if (!empty($oEmail->host) && $oEmail->host !== '.SYNTAX-ERROR.')
                        {
                            $aEmails[] = strtolower(trim($oEmail->mailbox.'@'.$oEmail->host));
                        }
                    }
                }
                else
                {
                    if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $sCcLine, $m))
                    {
                        $aEmails = array_map('strtolower', array_map('trim', $m[0]));
                    }
                }

                foreach ($aEmails as $sEmail)
                {
                    $this->LogMessage('info', "Found CC email: {$sEmail}");
                    $this->AttachContact($oTicket, $sEmail);
                }
            }
        }

        protected function AttachContact($oTicket, $sEmail)
        {
            try {
                // FIX 3: Tambah validasi email
                if (!filter_var($sEmail, FILTER_VALIDATE_EMAIL))
                {
                    $this->LogMessage('info', "Invalid email format: {$sEmail}");
                    return;
                }

                // Cari atau buat Person
                $oSearch = DBObjectSearch::FromOQL("SELECT Person WHERE email = :email");
                $oSet = new DBObjectSet($oSearch, [], ['email' => $sEmail]);

                if ($oSet->Count() == 0)
                {
                    $oPerson = new Person();
                    $sName = explode('@', $sEmail)[0];
                    $oPerson->Set('name', $sName);
                    $oPerson->Set('email', $sEmail);
                    
                    // FIX 4: Set org_id jika required (ambil dari ticket)
                    if ($oPerson->AttributeExists('org_id'))
                    {
                        $iOrgId = $oTicket->Get('org_id');
                        if ($iOrgId > 0)
                        {
                            $oPerson->Set('org_id', $iOrgId);
                        }
                    }
                    
                    $oPerson->DBInsert();
                    $this->LogMessage('info', "Created Person ID " . $oPerson->GetKey() . " for {$sEmail}");
                }
                else
                {
                    $oPerson = $oSet->Fetch();
                    $this->LogMessage('info', "Using existing Person ID " . $oPerson->GetKey() . " for {$sEmail}");
                }

                // Link ke ticket
                $oSearchLink = DBObjectSearch::FromOQL("SELECT lnkContactToTicket WHERE ticket_id = :tid AND contact_id = :pid");
                $oSetLink = new DBObjectSet($oSearchLink, [], [
                    'tid' => $oTicket->GetKey(),
                    'pid' => $oPerson->GetKey()
                ]);

                if ($oSetLink->Count() == 0)
                {
                    $oLink = new lnkContactToTicket();
                    $oLink->Set('ticket_id', $oTicket->GetKey());
                    $oLink->Set('contact_id', $oPerson->GetKey());
                    
                    if (MetaModel::IsValidAttCode(get_class($oLink), 'role_code'))
                    {
                        $oLink->Set('role_code', 'computed');
                    }
                    if (MetaModel::IsValidAttCode(get_class($oLink), 'contact_email'))
                    {
                        $oLink->Set('contact_email', $sEmail);
                    }
                    
                    $oLink->DBInsert();
                    $this->LogMessage('info', "Linked Person " . $oPerson->GetKey() . " to Ticket " . $oTicket->GetKey());
                }
                else
                {
                    $this->LogMessage('info', "Link already exists for Person " . $oPerson->GetKey() . " and Ticket " . $oTicket->GetKey());
                }
            }
            catch (Exception $e)
            {
                $this->LogMessage('info', "ERROR in AttachContact: " . $e->getMessage());
            }
        }
    }

    MetaModel::Init_AddExtension('AutoContactFromCCExtension');
    
    try {
        $sLogDir = defined('APPROOT') ? APPROOT . 'log' . DIRECTORY_SEPARATOR : __DIR__ . DIRECTORY_SEPARATOR;
        $sLogFile = $sLogDir . 'auto-contact-from-cc.log';
        file_put_contents($sLogFile, date('Y-m-d H:i:s') . " [INFO] AutoContactFromCCExtension initialized (FIXED VERSION)" . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {}
}