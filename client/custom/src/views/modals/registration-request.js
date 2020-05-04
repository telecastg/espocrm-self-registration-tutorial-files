
define('custom:views/modals/registration-request', 'views/modal', function (Dep) {

    return Dep.extend({

        cssName: 'password-change-request',

        className: 'dialog dialog-centered',

        template: 'custom:modals/registration-request',

        noFullHeight: true,
        
        setup: function () {
            this.buttonList = [
                {
                    name: 'submit',
                    label: 'Submit',
                    style: 'primary'
                },
                {
                    name: 'cancel',
                    label: 'Close'
                }
            ];

            this.headerHtml = this.translate('createRegistrationRequest', 'labels', 'RegistrationRequest');

            this.once('close remove', function () {
                if (this.$firstName) {
                    this.$firstName.popover('destroy');
                }
                if (this.$lastName) {
                    this.$lastName.popover('destroy');
                }            
                if (this.$emailAddress) {
                    this.$emailAddress.popover('destroy');
                }
                if (this.$cellPhone) {
                    this.$cellPhone.popover('destroy');
                }
            
            }, this);           
        },

        afterRender: function () {
            this.$firstName = this.$el.find('input[name="firstName"]');
            this.$lastName = this.$el.find('input[name="lastName"]');
            this.$emailAddress = this.$el.find('input[name="emailAddress"]');
            this.$cellPhone = this.$el.find('input[name="cellPhone"]');
        },

        actionSubmit: function () {
            var $firstName = this.$firstName;
            var $lastName = this.$lastName;
            var $emailAddress = this.$emailAddress;
            var $cellPhone = this.$cellPhone;

            var firstName = $firstName.val();
            var lastName = $lastName.val();
            var emailAddress = $emailAddress.val();
            var cellPhone = $cellPhone.val();

            // validate inputs
            var isValid = true;
            
            if (firstName == '') {
                isValid = false;

                var message = this.getLanguage().translate('firstNameCantBeEmpty', 'messages', 'RegistrationRequest');

                this.isPopoverFirstNameDestroyed = false;

                $firstName.popover({
                    container: 'body',
                    placement: 'bottom',
                    content: message,
                    trigger: 'manual'
                }).popover('show');

                var $cellFirstName = $firstName.closest('.form-group');
                $cellFirstName.addClass('has-error');

                $firstName.one('mousedown click', function () {
                    $cellFirstName.removeClass('has-error');
                    if (this.isPopoverFirstNameDestroyed) return;
                    $firstName.popover('destroy');
                    this.isPopoverFirstNameDestroyed = true;
                }.bind(this));
            }

            if (lastName == '') {
                isValid = false;

                var message = this.getLanguage().translate('lastNameCantBeEmpty', 'messages', 'RegistrationRequest');

                this.isPopoverLastNameDestroyed = false;

                $lastName.popover({
                    container: 'body',
                    placement: 'bottom',
                    content: message,
                    trigger: 'manual'
                }).popover('show');

                var $cellLastName = $lastName.closest('.form-group');
                $cellLastName.addClass('has-error');

                $lastName.one('mousedown click', function () {
                    $cellLastName.removeClass('has-error');
                    if (this.isPopoverLastNameDestroyed) return;
                    $lastName.popover('destroy');
                    this.isPopoverLastNameDestroyed = true;
                }.bind(this));
            }

            if (emailAddress == '') {
                isValid = false;

                var message = this.getLanguage().translate('emailAddressCantBeEmpty', 'messages', 'RegistrationRequest');

                this.isPopoverEmailAddressDestroyed = false;

                $emailAddress.popover({
                    container: 'body',
                    placement: 'bottom',
                    content: message,
                    trigger: 'manual'
                }).popover('show');

                var $cellEmailAddress = $emailAddress.closest('.form-group');
                $cellEmailAddress.addClass('has-error');

                $emailAddress.one('mousedown click', function () {
                    $cellEmailAddress.removeClass('has-error');
                    if (this.isPopoverEmailAddressDestroyed) return;
                    $emailAddress.popover('destroy');
                    this.isPopoverEmailAddressDestroyed = true;
                }.bind(this));
            }
            if (cellPhone == '') {
                isValid = false;

                var message = this.getLanguage().translate('cellPhoneCantBeEmpty', 'messages', 'RegistrationRequest');

                this.isPopoverCellPhoneDestroyed = false;

                $cellPhone.popover({
                    container: 'body',
                    placement: 'bottom',
                    content: message,
                    trigger: 'manual'
                }).popover('show');

                var $cellCellPhone = $cellPhone.closest('.form-group');
                $cellCellPhone.addClass('has-error');

                $cellPhone.one('mousedown click', function () {
                    $cellCellPhone.removeClass('has-error');
                    if (this.isPopoverCellPhoneDestroyed) return;
                    $cellPhone.popover('destroy');
                    this.isPopoverCellPhoneDestroyed = true;
                }.bind(this));
            }

            if (!isValid) return;
            
            var $submit = this.$el.find('button[data-name="submit"]');
            $submit.addClass('disabled');

            Espo.Ui.notify(this.translate('pleaseWait', 'messages'));

            // use plain javascript Ajax call to create a Registration Request object
            var xmlhttp = new XMLHttpRequest();
            var self = this;
            var url = '?entryPoint=anonymousRegistrationRequest';
            var payload = JSON.stringify({
                firstName: firstName,
                lastName: lastName,
                emailAddress: emailAddress,
                cellPhone: cellPhone
            });
            xmlhttp.onreadystatechange = function() { 
                if (xmlhttp.readyState === XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
                    if (xmlhttp.status === 200) {
                        console.log("xmlhttp.responseText = "+xmlhttp.responseText);
                        Espo.Ui.notify(false); 
                        var serverResponse = xmlhttp.responseText; 
                        var msg = self.translate(serverResponse, 'messages', 'RegistrationRequest');
                        self.$el.find('.cell[data-name="firstName"]').addClass('hidden');
                        self.$el.find('.cell[data-name="lastName"]').addClass('hidden');
                        self.$el.find('.cell[data-name="emailAddress"]').addClass('hidden');
                        self.$el.find('.cell[data-name="cellPhone"]').addClass('hidden');
                        $submit.addClass('hidden');
                        self.$el.find('.msg-box').removeClass('hidden');
                        self.$el.find('.msg-box').html('<span class="text-success">' + msg + '</span>');
                    }                       
                    else if (xmlhttp.status === 400) {
                        alert('There was an error 400');
                    }
                    else {
                        alert('something else other than 200 was returned');
                    }                    
                }                
            };
            xmlhttp.open("POST",url , true);
            xmlhttp.setRequestHeader("Content-type", "application/x-www-form-urlencoded");                
            xmlhttp.send("data="+payload);               
        }

    });
});
