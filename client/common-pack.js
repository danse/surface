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
// Developing file name: md5.js

/*
 * The MD5 function, which is the only thing defined into this file,
 * is used just by the g['signer'] object into common.js
 */

/*
 *
 *  MD5 (Message-Digest Algorithm)
 *  http://www.webtoolkit.info/
 *
 */
 
var MD5 = function (string) {
 
  function RotateLeft(lValue, iShiftBits) {
    return (lValue<<iShiftBits) | (lValue>>>(32-iShiftBits));
  }
 
  function AddUnsigned(lX,lY) {
    var lX4,lY4,lX8,lY8,lResult;
    lX8 = (lX & 0x80000000);
    lY8 = (lY & 0x80000000);
    lX4 = (lX & 0x40000000);
    lY4 = (lY & 0x40000000);
    lResult = (lX & 0x3FFFFFFF)+(lY & 0x3FFFFFFF);
    if (lX4 & lY4) {
      return (lResult ^ 0x80000000 ^ lX8 ^ lY8);
    }
    if (lX4 | lY4) {
      if (lResult & 0x40000000) {
        return (lResult ^ 0xC0000000 ^ lX8 ^ lY8);
      } else {
        return (lResult ^ 0x40000000 ^ lX8 ^ lY8);
      }
    } else {
      return (lResult ^ lX8 ^ lY8);
    }
  }
 
  function F(x,y,z) { return (x & y) | ((~x) & z); }
  function G(x,y,z) { return (x & z) | (y & (~z)); }
  function H(x,y,z) { return (x ^ y ^ z); }
  function I(x,y,z) { return (y ^ (x | (~z))); }
 
  function FF(a,b,c,d,x,s,ac) {
    a = AddUnsigned(a, AddUnsigned(AddUnsigned(F(b, c, d), x), ac));
    return AddUnsigned(RotateLeft(a, s), b);
  };
 
  function GG(a,b,c,d,x,s,ac) {
    a = AddUnsigned(a, AddUnsigned(AddUnsigned(G(b, c, d), x), ac));
    return AddUnsigned(RotateLeft(a, s), b);
  };
 
  function HH(a,b,c,d,x,s,ac) {
    a = AddUnsigned(a, AddUnsigned(AddUnsigned(H(b, c, d), x), ac));
    return AddUnsigned(RotateLeft(a, s), b);
  };
 
  function II(a,b,c,d,x,s,ac) {
    a = AddUnsigned(a, AddUnsigned(AddUnsigned(I(b, c, d), x), ac));
    return AddUnsigned(RotateLeft(a, s), b);
  };
 
  function ConvertToWordArray(string) {
    var lWordCount;
    var lMessageLength = string.length;
    var lNumberOfWords_temp1=lMessageLength + 8;
    var lNumberOfWords_temp2=(lNumberOfWords_temp1-(lNumberOfWords_temp1 % 64))/64;
    var lNumberOfWords = (lNumberOfWords_temp2+1)*16;
    var lWordArray=Array(lNumberOfWords-1);
    var lBytePosition = 0;
    var lByteCount = 0;
    while ( lByteCount < lMessageLength ) {
      lWordCount = (lByteCount-(lByteCount % 4))/4;
      lBytePosition = (lByteCount % 4)*8;
      lWordArray[lWordCount] = (lWordArray[lWordCount] | (string.charCodeAt(lByteCount)<<lBytePosition));
      lByteCount++;
    }
    lWordCount = (lByteCount-(lByteCount % 4))/4;
    lBytePosition = (lByteCount % 4)*8;
    lWordArray[lWordCount] = lWordArray[lWordCount] | (0x80<<lBytePosition);
    lWordArray[lNumberOfWords-2] = lMessageLength<<3;
    lWordArray[lNumberOfWords-1] = lMessageLength>>>29;
    return lWordArray;
  };
 
  function WordToHex(lValue) {
    var WordToHexValue="",WordToHexValue_temp="",lByte,lCount;
    for (lCount = 0;lCount<=3;lCount++) {
      lByte = (lValue>>>(lCount*8)) & 255;
      WordToHexValue_temp = "0" + lByte.toString(16);
      WordToHexValue = WordToHexValue + WordToHexValue_temp.substr(WordToHexValue_temp.length-2,2);
    }
    return WordToHexValue;
  };
 
  function Utf8Encode(string) {
    string = string.replace(/\r\n/g,"\n");
    var utftext = "";
 
    for (var n = 0; n < string.length; n++) {
 
      var c = string.charCodeAt(n);
 
      if (c < 128) {
        utftext += String.fromCharCode(c);
      }
      else if((c > 127) && (c < 2048)) {
        utftext += String.fromCharCode((c >> 6) | 192);
        utftext += String.fromCharCode((c & 63) | 128);
      }
      else {
        utftext += String.fromCharCode((c >> 12) | 224);
        utftext += String.fromCharCode(((c >> 6) & 63) | 128);
        utftext += String.fromCharCode((c & 63) | 128);
      }
 
    }
 
    return utftext;
  };
 
  var x=Array();
  var k,AA,BB,CC,DD,a,b,c,d;
  var S11=7, S12=12, S13=17, S14=22;
  var S21=5, S22=9 , S23=14, S24=20;
  var S31=4, S32=11, S33=16, S34=23;
  var S41=6, S42=10, S43=15, S44=21;
 
  string = Utf8Encode(string);
 
  x = ConvertToWordArray(string);
 
  a = 0x67452301; b = 0xEFCDAB89; c = 0x98BADCFE; d = 0x10325476;
 
  for (k=0;k<x.length;k+=16) {
    AA=a; BB=b; CC=c; DD=d;
    a=FF(a,b,c,d,x[k+0], S11,0xD76AA478);
    d=FF(d,a,b,c,x[k+1], S12,0xE8C7B756);
    c=FF(c,d,a,b,x[k+2], S13,0x242070DB);
    b=FF(b,c,d,a,x[k+3], S14,0xC1BDCEEE);
    a=FF(a,b,c,d,x[k+4], S11,0xF57C0FAF);
    d=FF(d,a,b,c,x[k+5], S12,0x4787C62A);
    c=FF(c,d,a,b,x[k+6], S13,0xA8304613);
    b=FF(b,c,d,a,x[k+7], S14,0xFD469501);
    a=FF(a,b,c,d,x[k+8], S11,0x698098D8);
    d=FF(d,a,b,c,x[k+9], S12,0x8B44F7AF);
    c=FF(c,d,a,b,x[k+10],S13,0xFFFF5BB1);
    b=FF(b,c,d,a,x[k+11],S14,0x895CD7BE);
    a=FF(a,b,c,d,x[k+12],S11,0x6B901122);
    d=FF(d,a,b,c,x[k+13],S12,0xFD987193);
    c=FF(c,d,a,b,x[k+14],S13,0xA679438E);
    b=FF(b,c,d,a,x[k+15],S14,0x49B40821);
    a=GG(a,b,c,d,x[k+1], S21,0xF61E2562);
    d=GG(d,a,b,c,x[k+6], S22,0xC040B340);
    c=GG(c,d,a,b,x[k+11],S23,0x265E5A51);
    b=GG(b,c,d,a,x[k+0], S24,0xE9B6C7AA);
    a=GG(a,b,c,d,x[k+5], S21,0xD62F105D);
    d=GG(d,a,b,c,x[k+10],S22,0x2441453);
    c=GG(c,d,a,b,x[k+15],S23,0xD8A1E681);
    b=GG(b,c,d,a,x[k+4], S24,0xE7D3FBC8);
    a=GG(a,b,c,d,x[k+9], S21,0x21E1CDE6);
    d=GG(d,a,b,c,x[k+14],S22,0xC33707D6);
    c=GG(c,d,a,b,x[k+3], S23,0xF4D50D87);
    b=GG(b,c,d,a,x[k+8], S24,0x455A14ED);
    a=GG(a,b,c,d,x[k+13],S21,0xA9E3E905);
    d=GG(d,a,b,c,x[k+2], S22,0xFCEFA3F8);
    c=GG(c,d,a,b,x[k+7], S23,0x676F02D9);
    b=GG(b,c,d,a,x[k+12],S24,0x8D2A4C8A);
    a=HH(a,b,c,d,x[k+5], S31,0xFFFA3942);
    d=HH(d,a,b,c,x[k+8], S32,0x8771F681);
    c=HH(c,d,a,b,x[k+11],S33,0x6D9D6122);
    b=HH(b,c,d,a,x[k+14],S34,0xFDE5380C);
    a=HH(a,b,c,d,x[k+1], S31,0xA4BEEA44);
    d=HH(d,a,b,c,x[k+4], S32,0x4BDECFA9);
    c=HH(c,d,a,b,x[k+7], S33,0xF6BB4B60);
    b=HH(b,c,d,a,x[k+10],S34,0xBEBFBC70);
    a=HH(a,b,c,d,x[k+13],S31,0x289B7EC6);
    d=HH(d,a,b,c,x[k+0], S32,0xEAA127FA);
    c=HH(c,d,a,b,x[k+3], S33,0xD4EF3085);
    b=HH(b,c,d,a,x[k+6], S34,0x4881D05);
    a=HH(a,b,c,d,x[k+9], S31,0xD9D4D039);
    d=HH(d,a,b,c,x[k+12],S32,0xE6DB99E5);
    c=HH(c,d,a,b,x[k+15],S33,0x1FA27CF8);
    b=HH(b,c,d,a,x[k+2], S34,0xC4AC5665);
    a=II(a,b,c,d,x[k+0], S41,0xF4292244);
    d=II(d,a,b,c,x[k+7], S42,0x432AFF97);
    c=II(c,d,a,b,x[k+14],S43,0xAB9423A7);
    b=II(b,c,d,a,x[k+5], S44,0xFC93A039);
    a=II(a,b,c,d,x[k+12],S41,0x655B59C3);
    d=II(d,a,b,c,x[k+3], S42,0x8F0CCC92);
    c=II(c,d,a,b,x[k+10],S43,0xFFEFF47D);
    b=II(b,c,d,a,x[k+1], S44,0x85845DD1);
    a=II(a,b,c,d,x[k+8], S41,0x6FA87E4F);
    d=II(d,a,b,c,x[k+15],S42,0xFE2CE6E0);
    c=II(c,d,a,b,x[k+6], S43,0xA3014314);
    b=II(b,c,d,a,x[k+13],S44,0x4E0811A1);
    a=II(a,b,c,d,x[k+4], S41,0xF7537E82);
    d=II(d,a,b,c,x[k+11],S42,0xBD3AF235);
    c=II(c,d,a,b,x[k+2], S43,0x2AD7D2BB);
    b=II(b,c,d,a,x[k+9], S44,0xEB86D391);
    a=AddUnsigned(a,AA);
    b=AddUnsigned(b,BB);
    c=AddUnsigned(c,CC);
    d=AddUnsigned(d,DD);
  }
 
  var temp = WordToHex(a)+WordToHex(b)+WordToHex(c)+WordToHex(d);
 
  return temp.toLowerCase();
}