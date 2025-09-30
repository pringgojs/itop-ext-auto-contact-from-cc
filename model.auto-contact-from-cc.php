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
            // Cari line CC
            $aMatches = [];
            if (preg_match('/^Cc:(.*)$/mi', $sHeaders, $aMatches))
            {
                $sCcLine = trim($aMatches[1]);
                if (!empty($sCcLine))
                {
                    // Parse alamat email
                    $aEmails = imap_rfc822_parse_adrlist($sCcLine, '');
                    foreach ($aEmails as $oEmail)
                    {
                        if (!empty($oEmail->host))
                        {
                            $sEmail = strtolower(trim($oEmail->mailbox.'@'.$oEmail->host));
                            $this->AttachContact($oTicket, $sEmail);
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
                $oPerson->Set('name', $sEmail);
                $oPerson->Set('email', $sEmail);
                $oPerson->DBInsert();
            }
            else {
                $oPerson = $oSet->Fetch();
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
                $oLink->DBInsert();
            }
        }
    }

    MetaModel::Init_AddExtension('AutoContactFromCCExtension');
}
