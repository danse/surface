// $Id: login.js 127 2010-11-30 16:14:38Z s242720-studenti $

window.onload = function(){
    g['login'].init();
};

g['login'] = {
    // Validity of text input fields (names must match those of the
    // html input elements)
    user: false,
    pass: false,
    // username sent to the server to check registration. Don't
    // confuse this.user (holding a boolean value) with this.username
    // (holding username string)
    username: '',
    // the ajax channel to the server. Channel initialization is made
    // into 'init', initializing it here doesn't works with explorer
    channel: null,
    // the mode of the request ('login' or 'register')
    mode: 'register',

    init: function(){
        // The timeout for ajax channels is a variable sent by the
        // server-side code
        ajax_timeout = getInput('ajax_timeout').value;
        this.channel = createChannel(['mode=checkuser'], login_handler,
                                     login_timeout, ajax_timeout);
        // The channel which checks the user is not signed
        this.channel.authentication = false;
        // Some browsers could keep valid values into the input
        // fields, so the validation must be done also here during the
        // initialization
        var inputs = ['user', 'pass'];
        for(i in inputs)
            this.validate(inputs[i]);
        // Initialize the 'signer' object that will update the salt
        g['signer'].init(getInput('salt').value, ajax_timeout);
        // Show the initial message
        login_notify_message(getInput('initial_message').value);
    },

    /* Validates the login input fields, and in case activates the
     * submit button. This function can be called with input_name
     * 'pass' or input_name 'user' */
    validate: function(input_name){
        // Old messages could hide from the user the fact that his
        // changes have consequences. When he changes an input value
        // his attention should be lead to the color of the input text
        // which tells if the value is valid or not
        login_notify_message('');
        // Currently user and pass fields must obey to the same regexp
        var regexp = /^[A-Za-z]\w{2,}$/;
        var target = document.getElementsByName(input_name)[0];
        // call the global validate function
        var valid = validate(target, regexp)
        this[input_name] = valid;
        // If the user inserted a valid usename, check wheter it is
        // registered or not
        if (valid && input_name === 'user')
            this.check(target.value);
        // If 'valid' is true (for example a valid password) try to
        // enable user controls
        this.enable_controls(valid);
    },

    /* Sends an ajax request to the server to check the existence of a
     * given username. 'handle' handles its response */
    check: function(username) {
        // Disable the controls, (their coherence with the user name
        // must be checked) they will be enabled by the handler of the
        // server response (function login_handler)
        this.enable_controls(false);
        this.checked = false;
        login_notify_message('Asking for your username to the server...');
        // Build the params string
        var params = ['user=' + username];
        // Channel send may fail (return false) if a request is
        // already sent
        if (this.channel.send(params)!==false){
            // Store the username to use it in login_handler
            this.username = username;
        }
    },

    // Enable or disable user controls, to avoid to send requests with
    // wrong parameters
    enable_controls: function(enable){
        if (enable){
            // Check the conditions to do valid requests to the
            // server. Conditions are: the current name has been
            // checked and username and password are valid
            if (this.checked && this.pass && this.user){
                getInput('submit').disabled = false;
                if (this.mode == 'login')
                    // Enable the user to try to create a new whiteboard
                    getInput('createsession').disabled = false;
            }
        }
        else{
            getInput('submit').disabled = true;
            getInput('createsession').disabled = true;
        }
    },

    submit: function(){
        var pass = getInput('pass').value;
        var user = getInput('user').value;
        var params = {'mode': this.mode, 'user': user};
        if (this.mode == 'register')
            // The register mode is the only one which sends the
            // plaintext password
            params['pass'] = pass;
        else{
            // In login mode retrieve radio input value for the
            // whiteboard parameter
            var inputs = document.getElementsByName('wb_id');
            for(var i=0; i<inputs.length; i++ ){
                if(inputs[i].checked){
                    params['wb_id'] = inputs[i].value;
                    break;
                }
            }
            if (params['wb_id'] === undefined){
                alert('No choosen sessions, first create at least one session');
                return;
            }
            else{
                // The signer must be updated: it will be used by
                // send_post to sign the request (in 'register' mode
                // the signature is not important)
                g['signer'].user = user;
                g['signer'].password = pass;
                // Store the 'signer' state, to be loaded on the next
                // page (the application page, see function onsvgload
                // into application.js) if the login succeeds
                g['signer'].store();
            }
        }
        send_post('index.php', params);
    }
};
/* login_handler and login_timeout must be on the global scope, in
 * order to be called by settimeout and onreadystatechange into the
 * channel object */
function login_timeout(){
    g['login'].channel.handle_timeout();
}
function login_handler(){
    var response = g['login'].channel.received();
    if(response !== false){
        // Check if the last username sent is like the one in the input field
        if (g['login'].username != document.forms['login_form'].user.value)
            g['login'].check(document.forms['login_form'].user.value);
        else {
            // Get the response stored in the <user> tag
            var responseObj =
                response.getElementsByTagName('user').item(0).firstChild.data;
            if (responseObj == 'registered') {
                login_notify_message('Username registered... Insert your password and login!');
                var button = 'Login';
            }
            else {
                login_notify_message('Username not registered... Please choose your password and register!');
                var button = 'Register';
            }
            // Set button
            getInput('submit').setAttribute('value', button);
            // Update the state of the login object
            g['login'].mode = button.toLowerCase();
            g['login'].checked = true;
            // Enable the 'submit' button (disabled into the 'check'
            // function)
            g['login'].enable_controls(true);
        }
    }
}

function session_create(){
    var name = prompt('Choose the name of the new session');
    // '-debug' and '-lock' keywords are needed for server hidden
    // files (see function login_page_send file
    // markup_send.php). This is not a security problem on the
    // server side, files with these names will just be skipped
    // while showing whiteboards to the users
    if(/(-debug)|(-lock)/.test(name))
        alert('Please don\'t use "-debug" or "-lock" as whiteboard name');
    else if(name != null){
        // From the name, clean all non-word characters and remove
        // starting underscores, to make it suitable for the name of the
        // session file.
        name = name.replace(/\W/g, '_').replace(/^_+/, '');
        if(name=='')
            alert('This session name is not valid. Please start the name with a letter.');
        else{
            var pass = document.forms['login_form'].pass.value;
            var user = document.forms['login_form'].user.value;
            // the signer must be updated: it will be called by send_post to
            // sign the request
            g['signer'].user = user;
            g['signer'].password = pass;
            var params = {'mode':'createsess',
                          'user':user,
                          'pass':pass,
                          'wb_id':name};
            send_post('index.php', params);
        }
    }
}

// Show a message for the user into the login page
function login_notify_message(message){
    var space = getById('message_space');
    if(space.childNodes[0])
        space.removeChild(space.childNodes[0]);
    space.appendChild(document.createTextNode(message));
}