<?php
// iTop Extension: Auto create contact from CC in Mail to Ticket Automation
// Compatible with iTop 3.1

if (!class_exists('AutoContactFromCCExtension'))
{
    class AutoContactFromCCExtension implements iApplicationObjectExtension
    {
        public function OnDBInsert($oObject, $oContextArgs = array())
        {
            // Trigger hanya untuk tiket (UserRequest & Incident)
            if ($oObject instanceof UserRequest || $oObject instanceof Incident)
            {
                // Ambil raw headers dari email (M2TA simpan di field 'origin_email_headers')
                $sHeaders = $oObject->Get('origin_email_headers');
                if (!empty($sHeaders))
                {
                    $this->ProcessCC($oObject, $sHeaders);
                }
            }
        }

        public function OnDBUpdate($oObject, $oChanges = null, $oContextArgs = array()) {}
        public function OnDBDelete($oObject, $oContextArgs = array()) {}
        public function OnDBClone($oObject, $oNewObj) {}
        public function OnIsModified($oObject) {}
        public function OnCheckToWrite($oObject) {}
        public function OnCheckToDelete($oObject) {}
        public function OnCheckToClone($oObject, $oNewObj) {}
        public function AfterInsertDB($oObject, $oContextArgs = array()) {}
        public function AfterUpdateDB($oObject, $oChanges = null, $oContextArgs = array()) {}
        public function AfterDeleteDB($oObject, $oContextArgs = array()) {}
        public function AfterCloneDB($oObject, $oNewObj) {}

        protected function ProcessCC($oTicket, $sHeaders)
        {
            // Extract Cc: header(s) robustly, including folded continuation lines.
            $aCcLines = array();
            $sCurrent = '';
            $lines = preg_split("/\r?\n/", $sHeaders);
            $collect = false;
            foreach ($lines as $line)
            {
                if (preg_match('/^\s*Cc:\s*(.*)/i', $line, $m))
                {
                    // Start of a Cc header
                    if ($collect && $sCurrent !== '')
                    {
                        $aCcLines[] = $sCurrent;
                    }
                    $sCurrent = $m[1];
                    $collect = true;
                }
                elseif ($collect && preg_match('/^\s+(.*)/', $line, $m))
                {
                    // Folded header continuation
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

            foreach ($aCcLines as $sCcLine)
            {
                $sCcLine = trim($sCcLine);
                if ($sCcLine === '') continue;

                // Log that we started processing a Cc header for this ticket
                try {
                    IssueLog::Trace("AutoContactFromCC: Processing Cc for ticket " . $oTicket->GetKey() . " -> {$sCcLine}");
                } catch (Exception $e) {
                    // best-effort logging, ignore failures
                }

                // Prefer imap_rfc822_parse_adrlist when available
                $aEmails = array();
                if (function_exists('imap_rfc822_parse_adrlist'))
                {
                    $aEmails = imap_rfc822_parse_adrlist($sCcLine, '');
                    foreach ($aEmails as $oEmail)
                    {
                        if (!empty($oEmail->host))
                        {
                            $sEmail = strtolower(trim($oEmail->mailbox.'@'.$oEmail->host));
                            try {
                                IssueLog::Trace("AutoContactFromCC: Found CC email {$sEmail} for ticket " . $oTicket->GetKey());
                            } catch (Exception $e) {}
                            $this->AttachContact($oTicket, $sEmail);
                        }
                    }
                }
                else
                {
                    // Fallback: extract email addresses with a regex
                    if (preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $sCcLine, $m))
                    {
                        foreach ($m[0] as $sEmail)
                        {
                            try { IssueLog::Trace("AutoContactFromCC: Found CC email {$sEmail} (fallback regex) for ticket " . $oTicket->GetKey()); } catch (Exception $e) {}
                            $this->AttachContact($oTicket, strtolower(trim($sEmail)));
                        }
                    }
                }
            }
        }

        protected function AttachContact($oTicket, $sEmail)
        {
            // Cek apakah Person sudah ada
            $oSearch = DBObjectSearch::FromOQL("SELECT Person WHERE email = :email");
            $oSet = new DBObjectSet($oSearch, [], ['email' => $sEmail]);

            if ($oSet->Count() == 0)
            {
                // Buat person baru
                $oPerson = new Person();
                // Use the local-part as a fallback name, keep the email in the email attribute
                $sName = $sEmail;
                $aParts = explode('@', $sEmail);
                if (count($aParts) > 0 && !empty($aParts[0]))
                {
                    $sName = $aParts[0];
                }
                $oPerson->Set('name', $sName);
                $oPerson->Set('email', $sEmail);
                $oPerson->DBInsert();
                try { IssueLog::Info("AutoContactFromCC: Created Person '" . $oPerson->GetKey() . "' for email {$sEmail}"); } catch (Exception $e) {}
            }
            else {
                $oPerson = $oSet->Fetch();
                try { IssueLog::Trace("AutoContactFromCC: Reusing existing Person '" . $oPerson->GetKey() . "' for email {$sEmail}"); } catch (Exception $e) {}
            }

            // Tambahkan ke Contact List jika belum ada
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
                // Mark as automatically added so it's easy to distinguish
                if ($oLink->AttributeExists('role_code'))
                {
                    $oLink->Set('role_code', 'computed');
                }
                // Also store the contact email and name on the link if attributes exist
                if ($oLink->AttributeExists('contact_email'))
                {
                    $oLink->Set('contact_email', $sEmail);
                }
                if ($oLink->AttributeExists('contact_name'))
                {
                    $oLink->Set('contact_name', $oPerson->Get('name'));
                }
                $oLink->DBInsert();
                try { IssueLog::Info("AutoContactFromCC: Created lnkContactToTicket link ticket=" . $oTicket->GetKey() . " contact=" . $oPerson->GetKey()); } catch (Exception $e) {}
            }
        }
    }

    MetaModel::Init_AddExtension('AutoContactFromCCExtension');
}
