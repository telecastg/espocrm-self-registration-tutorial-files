<?php
namespace Espo\Custom\EntryPoints;

use \Espo\Core\Exceptions\NotFound;
use \Espo\Core\Exceptions\Forbidden;
use \Espo\Core\Exceptions\BadRequest;


class AnonymousRegistrationRequest extends \Espo\Core\EntryPoints\Base
{
    public static $authRequired = false;

    // default action
    public function run()            
    {
        //convert the JSON string received from the Ajax call into a PHP associative array
        $data = json_decode($_REQUEST['data'], true);       
        // call the Service which will process the request and return the appropriate message:
        // existingRegistration or registrationRequestReceived
        $response = $this->getContainer()->get('serviceFactory')->create('RegistrationRequest')->processAnonymousRegistrationRequest($data);
        // send response back to the Ajax request
        echo $response;
    }
        
}

