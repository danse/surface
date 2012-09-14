// $Id: common.js 131 2010-12-09 18:13:10Z s242720-studenti $


// The global object containing all global variables and objects
var g = {};

// With firefox, when a new page is loaded (when send_post is called
// and when the import form is sent) all ajax requests that are
// waiting terminate with errors. This variable is set by send_post
// and read by all ajax channels to correctly handle this situation
g['abort_all_ajax_requests'] = false;

// Very simple function to improve readability
function getById(id){return document.getElementById(id)};

// To avoid doubing 'name' and 'id' attributes (for valid HTML), input
// elements just have the 'name' attribute, and this function is
// required to easily access them
function getInput(name){
    var inputs = document.getElementsByName(name);
    return inputs[0];
}

/* helper function to set multiple attributes in an object */
function sa(object, attributes) {
    for (var key in attributes)
        object.setAttribute(key, attributes[key]);
}

// These functions all use the same technique to retrieve properties
// belonging to the handled event, in a cross-browser way. Actually,
// all these checks are necessary for Explorer
function cross_target(event){
    return event.target ? event.target : window.event.srcElement;
}
function cross_which(event){
    //return event ? event.which : window.event.keyCode;
    if(!event)
        event = window.event;
    return event.keyCode;
}
function cross_shift(event){
    return event ? event.shiftKey : window.event.shiftKey;
}

/* Centralized browser sniffing: the code is short, but an unified
 * function allows to easy update the sniffing technique */
function sniff(){
    if(navigator.userAgent.indexOf('Firefox') != -1)
        return 'firefox';
    else if(navigator.userAgent.indexOf('Chrome') != -1)
        return 'chrome';
    else if(navigator.userAgent.indexOf('MSIE') != -1)
        return 'explorer';
}

/* Validate an input field against a regular expression, changing its
 * style accordingly */
function validate(target, regexp){
    if(regexp.test(target.value)){
        target.className = target.className.replace(/ invalid/, '');
        return true;
    }
    else{
        if(!/invalid/.test(target.className))
            target.className = target.className + ' invalid';
        return false;
    }
}

/* Send a POST request, in order to refresh the page without writing
 * on the address bar */
function send_post(address, params){
    params['signature'] = g['signer'].get_signature();
    var logout_form = document.createElement('form');
    for (key in params){
        var input = document.createElement('input');
        sa(input, {'type':'hidden', 'name':key, 'value':params[key]});
        logout_form.appendChild(input);
    }
    sa(logout_form, {'method':'POST', 'action':address});
    document.body.appendChild(logout_form);
    g['abort_all_ajax_requests'] = true;
    logout_form.submit();
}

/*
 * Creates an object which sends ajax requests to the server, handling
 * a timeout (after which the request is sent again) and calling an
 * extern-defined function when the server's response arrives. Objects
 * built on this class are not truly objects, because many of their
 * functions need to stay into the global scope, in order to be called
 * by the setTimeout and onreadystatechange functions
 */
function createChannel(params, change_handler, timeout_handler, timeout){
    var channel = {
        /* Constants */
        url    : 'index.php',
        method : 'POST',

        /* Attributes */
        authentication  : true,
        constant_params : params,
        change_handler  : change_handler,
        timeout_handler : timeout_handler,
        // All channels (but the receiver channel) use the same
        // timeout value, but it is a parameter sent by the server
        timeout_value   : timeout,

        /* State */
        timeout_id : null,
        request    : null,  // reference to XMLHttpRequest object
        timeout_counter : 0
    };
  
    /* Private methods */

    // XMLHttpRequest object creation
    channel.create_request = function(){
        // This should work for all browsers except IE6 and older
        try {
            this.request = new XMLHttpRequest();
        }
        catch(e) {
            // Assume IE6 or older
            var versions = new Array('MSXML2.XMLHTTP.6.0',
                                     'MSXML2.XMLHTTP.5.0',
                                     'MSXML2.XMLHTTP.4.0',
                                     'MSXML2.XMLHTTP.3.0',
                                     'MSXML2.XMLHTTP',
                                     'Microsoft.XMLHTTP');
            // Try every prog id until one works
            for(v in versions){
                try{
                    this.request = new ActiveXObject(versions[v]);
                    if(this.request)
                        break;
                } catch(e) {}
            }
        }
        if (!this.request)
            alert('Error creating the XMLHttpRequest object.');
    };

    channel.send_message = function(msg){
        // The presence of the 'svg_container' object is used
        // to understand if the channel is working in a login
        // or an application page
        if (getById('svg_container') === null)
            login_notify_message(msg);
        else
            application_notify_message(msg);
    };

    /* Public methods */

    // Sends a request. Returns false if the channel is waiting for an
    // old request to receive its response. If params is unspecified,
    // reuse last params.
    channel.send = function(params){
        if(!this.request)
            this.create_request();
        if(this.timeout_id)
            // There is a pending send
            return false;
        // When 'send' is called without params, retrieve last used params
        if(params){
            // If this is a kind of channel which requires
            // authentication, add signature (this is the default)
            if (this.authentication)
                params.push('signature='+g['signer'].get_signature());
            this.last_params = params;
        }
        else
            var params = this.last_params;
        this.request.open(this.method, this.url, true);
        this.request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        this.request.onreadystatechange = this.change_handler;
        param_string = params.concat(this.constant_params).join('&');
        this.request.send(param_string);
        this.timeout_id = setTimeout(this.timeout_handler, this.timeout_value);
        return true;
    };
    // Called by the extern timeout handler: resets the request object
    // and repeats the send
    channel.handle_timeout = function(){
        this.timeout_id = null;
        this.request.onreadystatechange = null;
        this.request = null;
        this.send();

        this.send_message('The server has been slow responding to our request, '+
                          'retrying to send (attempt number '+(this.timeout_counter++)+')');
    };
    // Called by the extern changereadystate handler: checks request
    // state and returns the request if the case
    channel.received = function(){
        if (this.request.readyState != 4)
            return false;
        // Cancel the timeout
        clearTimeout(this.timeout_id);
        this.timeout_id = null;
        // Collect conditions for errors
        if (this.request.status != 200) {
            // *http error*: maybe the user network is down. Try to
            // send the more explicative statusText to the user, but
            // if this fails (this can happen with firefox) send
            // simply the status
            try{
                var msg = 'Server comunication failed: status "'+this.request.statusText+'"';
            }
            catch(e){
                var msg = 'Server comunication failed: status "'+this.request.status+'"';
            }
        }
        else{
            try{
                var response = this.request.responseXML.documentElement;
                // These are used to force explorer (and other
                // browsers) to parse the XML document to spot errors
                response.lastChild;
                response.childNodes;
            }
            catch(e){
                // *malformed response*: this could even be a php
                // error. Firefox raises an exception on
                // this.request.responseXML while Explorer raises an
                // exception on response.lastChild
                var msg = 'A server error occurred:\n\n'+this.request.responseText;
            }
        }
        // Here we have an error message or a valid response
        if(msg){
            // global variable set when changing the page, see the
            // description on the top of this file
            if (g['abort_all_ajax_requests']===false){
                if (confirm(msg+'\n\nTry again?'))
                    this.send();
                else
                    this.send_message('Unrecoverable error while comunicating with the server');
            }
            return false;
        }
        else{
            // If we warned the user about timeouts, now tell that
            // it's okay
            if (this.timeout_counter > 0){
                this.send_message('Transmission attempt '+this.timeout_counter+' successful');
                this.timeout_counter = 0;
            }
            return response;
        }
    };
    channel.create_request();
    return channel;
}

/*
 * The signer object. It holds the password and the server's salt, and
 * uses them to produce a signature for requests. This is used on all
 * pages, and has the functions 'store' and 'load' to save its state
 * when the page is changed (from login to application, or resizes of
 * the application page)
 */
g['signer'] = {
    // Time between a correct response and a new request (6 minutes in
    // milliseconds, should be shorter then $server_timestamp_validity
    // into updates.php)
    cycle_timeout: 360000,
    /* The only private method */
    start_cycle: function(){
        this['channel'] = createChannel(['mode=update_salt'], signer_handler,
                                        signer_timeout, this['ajax_timeout']);
        // The channel which requires the salt is not signed
        this['channel'].authentication = false;
        this.channel.send([]);
    },
    /* Public methods */
    init: function(salt, ajax_timeout){
        this['salt'] = salt;
        this['ajax_timeout'] = ajax_timeout;
        this.start_cycle();
    },
    store: function(){
        window.name =
        '({password:"'+this.password+
        '",salt:"'+this.salt+
        '",user:"'+this.user+
        '",ajax_timeout:'+this.ajax_timeout+'})';
    },
    load: function(){
        var state = eval(window.name);
        this['password'] = state['password'];
        this['salt'] = state['salt'];
        this['user'] = state['user'];
        this['ajax_timeout'] = state['ajax_timeout'];
        this.start_cycle();
    },
    get_signature: function(){
        return this.user+':'+this.salt+':'+MD5(this.salt+':'+this.password);
    }
};

function signer_send(){
    g['signer'].channel.send([]);
}

function signer_handler(){
    var response = g['signer'].channel.received();
    if(response !== false){
        g['signer'].salt =
            response.getElementsByTagName('salt').item(0).firstChild.data;
        setTimeout(signer_send, g['signer'].cycle_timeout);
    }
}

function signer_timeout(){
    g['signer'].channel.handle_timeout();
}
