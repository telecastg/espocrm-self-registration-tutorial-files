<?php
namespace Espo\Custom\Services;

class RegistrationRequest extends \Espo\Core\Templates\Services\Base
{
    
    public function processAnonymousRegistrationRequest($data)
    {
        $firstName = $data['firstName'];
        $lastName = $data['lastName'];
        $emailAddress = $data['emailAddress'];
        $cellPhone = $data['cellPhone'];
        // strip all non-numeric characters from the cell phone number
        $numericPhone = preg_replace('/[^0-9]/', '', $cellPhone);
        // make the email all lower caps
        $lowCapsEmail = strtolower($emailAddress);        
        // verify that the applicant is not already registered
        $userId = '';
        $contactId = '';
        $pdo = $this->getEntityManager()->getPDO();     
        // set criteria to identify an existing portal user by matching email and numeric phone number
        $sql = 'Select user.user_name As userName, '
                . 'user.id As `userId` '
                . 'From user '
                . 'Inner Join entity_email_address On entity_email_address.entity_id = user.id '
                . 'Inner Join email_address On email_address.id = entity_email_address.email_address_id '
                . 'Inner Join contact On user.contact_id = contact.id '
                . 'Where user.type = "portal" '
                . 'And phone_number.`numeric` ="'.$numericPhone.'" '
                . 'And email_address.lower = "'.$pdo->quote($lowCapsEmail).'" '
                . 'And entity_phone_number.entity_type = "Contact" '
                . 'And entity_email_address.entity_type = "Contact" ';
        $sth = $pdo->prepare($sql);
        // execute query
        $sth->execute();
        //convert the results into an associative array
        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);
        // if the applicant was found, send the username to the applicant and ask to request a password change if forgotten
        if(count($rows)>0) {
            $userId = $rows[0]['userId'];
            $username = $rows[0]['userName'];
            $userObject = $this->getEntityManager()->getRepository('User')->where(['id'=>$userId])->findOne();
            $email = $this->getEntityManager()->getEntity('Email');
            $subject = 'Registration request for EspoCRM'; // substitue EspoCRM for your website name
            $body = '<p>Thanks for your registration request</p> '
                    . '<p>Our records indicate that a user is already registered with your email address and phone number with username <b>'.$username.'</b></p>'
                    . '<p>If you don\'t remember your password, please go back to the portal and click on "Forgot Password ?" to request a new one</p>'
                    . '<p>Thank You</p>';
            // substitute the content of $body to create your own message if you would like.
            $email->set([
                'subject' => $subject,
                'body' => $body,
                'to' => $emailAddress
            ]);
            if ($this->getConfig()->get('smtpServer')) {
                $this->getMailSender()->useGlobal();
            } else {
                $this->getMailSender()->useSmtp(array(
                    'server' => $this->getConfig()->get('internalSmtpServer'),
                    'port' => $this->getConfig()->get('internalSmtpPort'),
                    'auth' => $this->getConfig()->get('internalSmtpAuth'),
                    'username' => $this->getConfig()->get('internalSmtpUsername'),
                    'password' => $this->getConfig()->get('internalSmtpPassword'),
                    'security' => $this->getConfig()->get('internalSmtpSecurity'),
                    'fromAddress' => $this->getConfig()->get('internalOutboundEmailFromAddress', $this->getConfig()->get('outboundEmailFromAddress'))
                ));
            }
            $this->getMailSender()->send($email);
            // send message back to the AnonymousRegistrationRequest entryPoint
            return "existingRegistration";
        } else {
            // if the applicant was not found as current user, create a Registration Request record 
            $registrationRequestObject = $this->getEntityManager()->getEntity('RegistrationRequest');
            $registrationRequestObject->populateDefaults();
            $registrationRequestObject->set('name',$numericPhone);            
            $registrationRequestObject->set('firstName',$firstName);
            $registrationRequestObject->set('lastName',$lastName);
            $registrationRequestObject->set('emailAddress',$emailAddress);
            $registrationRequestObject->set('cellPhone',$cellPhone);
            // persist the new registration request
            $this->getEntityManager()->saveEntity($registrationRequestObject); 
            // notify the administrator for approval or rejection of the request
            $this->notifyAdministratorOfRegistrationRequest($firstName, $lastName, $emailAddress, $cellPhone);                                    
            // send message back to the entryPoint acknowledging receipt
            return "registrationRequestReceived";
        }
    }

    public function notifyAdministratorOfRegistrationRequest($firstName, $lastName, $emailAddress, $cellPhone)
    {
        // get the administrator's email
        $eMailList = [];
        $pdo = $this->getEntityManager()->getPDO();        
        $sql1 = 'Select email_address.lower as `email` 
                From entity_email_address 
                Inner Join email_address On entity_email_address.email_address_id = email_address.id 
                Inner Join user On entity_email_address.entity_id = user.id
                Where entity_email_address.entity_type = "User" And user.type = "admin"
                Group By email_address.lower';   
        $sth1 = $pdo->prepare($sql1);
        $sth1->execute();
        $rows = $sth1->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $emailList[] = $row['email'];
        }
        // initialize the email sender service 
        $mailSender = $this->getEntityManager()->getContainer()->get('mailSender');           
        // create an email entity and load the main properties
        $email = $this->getEntityManager()->getEntity('Email');
        if(isset($_SERVER['HTTPS'])){
            $protocol = ($_SERVER['HTTPS'] && $_SERVER['HTTPS'] != "off") ? "https" : "http";
        } else {
            $protocol = 'http';
        }   
        $portalUrl = $protocol . "://" . $_SERVER['HTTP_HOST'].'/execution_manager/portal/tenant';             
        $subject = 'Registration request received from '.$firstName.' '.$lastName.' at '.$zipCode; 
        $body = 'Request details:<br/> Name: '.$firstName.' '.$lastName.'<br/> Cell Phone: '.$cellPhone.'<br/> eMail: '.$emailAddress;            
        $fromAddress = $this->getConfig()->get('outboundEmailFromAddress');
        // send email to Administrator(s) advising of the registration request for approval
        foreach($emailList as $receipient) {
            $email->set(array(
                'subject' => $subject,
                'body' => $body,
                'isHtml' => true,
                'from' => $fromAddress,
                'to' => $receipient,
                'isSystem' => true
            ));
            // send the email 
            if($this->getConfig()->get('smtpServer')){
                $mailSender->send($email);    
            }            
        }
    }        
}
