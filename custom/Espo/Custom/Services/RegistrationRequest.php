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
        
    public function createNewPortalUser($contactId) 
    {
        $entityManager = $this->getEntityManager();
        $pdo = $entityManager->getPDO();        
        // get the existing Contact instance
        $contactObject = $entityManager->getRepository('Contact')->where(['id'=>$contactId])->findOne();
        // get the tenant portal id
        $portalId='';
        $portalObject = $entityManager->getRepository('Portal')->where(['customId'=>'tenant'])->findOne();
        if($portalObject) {
            $portalId = $portalObject->get("id");                            
        }        
        // get the tenant portal role id
        $portalRoleId = '';
        $portalRoleObject = $entityManager->getRepository('PortalRole')->where(['name'=>'Tenant'])->findOne();
        if($portalRoleObject) {
            $portalRoleId = $portalRoleObject->get("id");
        }          
        // create a new User instance
        $userObject = $entityManager->getEntity('User');   
        // get contact name information 
        $firstName = $contactObject->get("firstName");
        $lastName = $contactObject->get("lastName");
        // get the all of the contact's phone numbers  
        $phoneNumberData = $entityManager->getRepository('PhoneNumber')->getPhoneNumberData($contactObject);
        $formattedPhone = '';
        foreach ($phoneNumberData as $row) {
            // select the one flagged as "primary" 
            if($row->primary) {
                $formattedPhone = trim($row->phoneNumber);
            }
        }
        // remove all non numeric characters
        $numericPhone = preg_replace('/[^0-9]/', '', $formattedPhone);   
        $phoneLast4Digits = substr($numericPhone, -4);
        // combine the contact's first name, last name and last four digits of primary phone number to create a new user name
        $usename = trim(strtolower($firstName)).".".trim(strtolower($lastName)).".".$phoneLast4Digits;
        // encrypt the numeric phone to use as password
        /* inicializamos objetos */
        $fileM = new \Espo\Core\Utils\File\Manager();
        $config = new \Espo\Core\Utils\Config($fileM);
        $passwordHash = new \Espo\Core\Utils\PasswordHash($config);
        /* encriptamos */
        $encodedPassword = $passwordHash->hash($numericPhone,true); 
        // load the new user entity instance
        $userObject->set("firstName",$firstName);
        $userObject->set("lastName",$lastName);
        $userObject->set("userName",$usename);            
        $userObject->set("password",$encodedPassword);    
        $userObject->set("confirmPasword",$encodedPassword);
        $userObject->set("contactId",$contactId);            
        $userObject->set("isActive",true);
        $userObject->set("isPortalUser",1); 
        $userObject->set("type","portal");        
        // persist the new user instance
        $entityManager->saveEntity($userObject);
        // get the new user id
        $newUserObject = $entityManager->getRepository('User')->where(['contactId'=>$contactId])->findOne();
        $userId = $newUserObject->get('id');
        // link the new user to the Contact's account               
        $accountId = $contactObject->get('accountId');
        $sql = "INSERT INTO `account_portal_user`(`user_id`,`account_id`) VALUES ('".$userId."','".$accountId."')"; 
        $pdo->query($sql);               
        // link the new user with the contact's email address
        $emailAddressId = "";
        $sql = "SELECT `email_address_id` FROM entity_email_address WHERE `entity_type` = 'Contact' AND `primary` = 1 AND `deleted` = 0 AND `entity_id` = '".$contactId."'";
        $data = $pdo->query($sql)->fetchAll();
        if($data) {
            $emailAddressId = $data[0]['email_address_id'];                
        }
        if($emailAddressId) {
            $sql = "SELECT * FROM `entity_email_address` WHERE `entity_id` = '".$userId."' AND `email_address_id` = '".$emailAddressId."' AND `entity_type` = 'User'";
            $data = $pdo->query($sql)->fetchAll();
            if(!$data) {
                $sql = "INSERT INTO `entity_email_address`(`entity_id`, `email_address_id`, `entity_type`, `primary`) VALUES ('".$userId."','".$emailAddressId."','User',1)";
                $pdo->query($sql);                    
            }            
        }
        // link the new user with the contact's phone number
        $phoneNumberId = "";
        $sql = "SELECT `phone_number_id` FROM entity_phone_number WHERE `entity_type` = 'Contact' AND `primary` = 1 AND `deleted` = 0 AND `entity_id` = '".$contactId."'";
        $data = $pdo->query($sql)->fetchAll();
        if($data) {
            $phoneNumberId = $data[0]['phone_number_id'];                
        }     
        if($phoneNumberId) {
            $sql = "SELECT * FROM `entity_phone_number` WHERE `entity_id` = '".$userId."' AND `phone_number_id` = '".$phoneNumberId."' AND `entity_type` = 'User'";
            $data = $pdo->query($sql)->fetchAll();
            if(!$data) {
                $sql = "INSERT INTO `entity_phone_number`(`entity_id`, `phone_number_id`, `entity_type`, `primary`) VALUES ('".$userId."','".$phoneNumberId."','User',1)";
                $pdo->query($sql);                    
            }            
        }            
        // link the new user with the tenant portal    
        if($portalId) {
            $sql = "INSERT INTO `portal_user`(`portal_id`, `user_id`) VALUES ('".$portalId."','".$userId."')"; 
            $pdo->query($sql);             
        }
        // link the new user with the portal role
        if($portalRoleId) {
            $sql = "INSERT INTO `portal_role_user`(`portal_role_id`,`user_id`) VALUES ('".$portalRoleId."','".$userId."')"; 
            $pdo->query($sql);                                            
        }           
        // after creating a new portal user record notify of new credentials 
        $this->sendPassword($newUserObject, $numericPhone); 
    }

    protected function hashPassword($password)
    {
        $config = $this->getConfig();
        $passwordHash = new \Espo\Core\Utils\PasswordHash($config);
        return $passwordHash->hash($password);
    }
    
    protected function sendPassword($user, $password)
    {
        $emailAddress = $user->get('emailAddress');
        if (empty($emailAddress)) {
            return;
        }
        $email = $this->getEntityManager()->getEntity('Email');
        if (!$this->getConfig()->get('smtpServer') && !$this->getConfig()->get('internalSmtpServer')) {
            return;
        }
        $templateFileManager = $this->getEntityManager()->getContainer()->get('templateFileManager');
        $siteUrl = $this->getConfig()->getSiteUrl() . '/';
        $data = [];
        if ($user->isPortal()) {
            $subjectTpl = $templateFileManager->getTemplate('accessInfoPortal', 'subject', 'User');
            $bodyTpl = $templateFileManager->getTemplate('accessInfoPortal', 'body', 'User');
            $urlList = [];
            $portalList = $this->getEntityManager()->getRepository('Portal')->distinct()->join('users')->where(array(
                'isActive' => true,
                'users.id' => $user->id
            ))->find();
            foreach ($portalList as $portal) {
                if ($portal->get('customUrl')) {
                    $urlList[] = $portal->get('customUrl');
                } else {
                    $url = $siteUrl . 'portal/';
                    if ($this->getConfig()->get('defaultPortalId') !== $portal->id) {
                        if ($portal->get('customId')) {
                            $url .= $portal->get('customId');
                        } else {
                            $url .= $portal->id;
                        }
                    }
                    $urlList[] = $url;
                }
            }
            if (!count($urlList)) {
                return;
            }
            $data['siteUrlList'] = $urlList;
        } else {
            $subjectTpl = $templateFileManager->getTemplate('accessInfo', 'subject', 'User');
            $bodyTpl = $templateFileManager->getTemplate('accessInfo', 'body', 'User');
            $data['siteUrl'] = $siteUrl;
        }
        $data['password'] = $password;
        $htmlizer = new \Espo\Core\Htmlizer\Htmlizer($this->getFileManager(), $this->getDateTime(), $this->getNumber(), null);
        $subject = $htmlizer->render($user, $subjectTpl, null, $data, true);
        $body = $htmlizer->render($user, $bodyTpl, null, $data, true);
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
    }
    
}
