// $Id: application.js 132 2010-12-13 10:53:17Z s242720-studenti $

// g is a global variable containing all client-side state
g['debug'] = false;          // enable extra output

// S is the main global object, which contains
// all parameters sent by the server side. S is initialized by onsvgload()
// and unmodified afterwards.
S = {};

/*
 * Starts on onsvgload event. Initialize the application state.
 */
window.onsvgload = function() {
    // Get client-side variables from server-side ones embedded into
    // document nodes
    var server_vars = ['user', 'client_id', 'width', 'height', 'svg_w', 'svg_h',
                       'slides', 'user_id', 'obj_prefix', 'send_id',
                       'ajax_timeout', 'update_timeout'];
    var form = getById('session_data');
    for (v in server_vars)
        S[server_vars[v]] = form[server_vars[v]].value;

    g['size_adapter'].init(S['svg_w'], S['svg_h']);

    mylog('starting initWhiteboard');
    initWhiteboard();
    mylog('starting initMenu');
    initMenu();

    // Init channels (the receiver channel is the only channel which
    // uses a different timeout value (update_timeout))
    var rec_par = ['client_id='+S['client_id'], 'mode=read'];
    g['receiver'].channel = createChannel(rec_par, receiver_handler,
                                          receiver_timeout, S['update_timeout']);
    var send_par = ['client_id='+S['client_id'], 'mode=write'];
    g['sender'].channel = createChannel(send_par, sender_handler,
                                        sender_timeout, S['ajax_timeout']);
    g['sender'].send_id = g['send_id'];

    if (S['slides'] !== '')
        load_slides(S['slides']);

    // Initialize the signer and start the update cycle
    g['signer'].load();
    // Start the receive cycle
    receiver_send();

    mylog('application initialization completed');
};

// Generator of object ids for the whiteboard and the chat.
g['idObj'] = {
    current:0,
    get_new:function(){
        return S['obj_prefix'] + '_' + this.current++;
    }
};

/* simple log function -- writes into the notify area of the
 * whiteboard. We must do this because explorer doesn't supports
 * console.log */
function mylog(content) {
    if (g['debug']){
        // Explorer doesn't supports the console objects
        if (console !== null)
            console.log(content);
        application_notify_message(content);
    }
}

/* Shows a short tip for the user, better readable when g['debug'] is
 * false. It can be called with unexistent tip keys (for example when
 * tips should match tool names) */
function user_help(tip) {
    // Tip strings shouldn't be too long, they must fit into the notify area
    var tips = {
        'edit': "Click to edit lines, rectangles, circles, polygons and polylines",
        'polyline': "Click to add segments, double click to close the element",
        'polygon': "Click to add segments, double click to close the element",
        'path': "Click on the button again to draw multiple paths",
        'text': "Shift+Enter adds a newline, Enter alone closes the element",
        'link': "Insert a valid url like http://www.site.com",
        'image': "Write an url like http://www.site.com/image.png",
        'multipath': "Strokes drawn while the button is pushed will move together",
        'close_draw': "Please finish the current draw (click on the canvas) before changing the tool",
        'img_err': "There was an error on your image, maybe the address you inserted was wrong"
    };
    var message = tips[tip];
    if (message == undefined)
        message = '';
    application_notify_message(message);
}

/* Shows a message into the notify area */
function application_notify_message(msg) {
    // Delete the old text
    var space_div = getById('log_space');
    var logtext = getById('log_space').childNodes[0];
    // logtext can be undefined when the app has just started for
    // example
    if(logtext != undefined)
        space_div.removeChild(logtext);
    // Create and append the new text
    space_div.appendChild(document.createTextNode(msg));
}

/*
 * The function called by the 'R' resize dot on the top left corner of
 * the application
 */
function frame_resize() {
    var rect = document.createElement('div');
    rect.setAttribute('id', 'frame_size');
    document.body.appendChild(rect);
    application_notify_message('Click again to resize the whiteboard');

    document.onmousemove = function(event) {
        event = event ? event : window.event;
        var rect = getById('frame_size');
        rect.style.width  = event.clientX+'px';
        rect.style.height = event.clientY+'px';
    };
    document.onmousedown = function(event) {
        var rect = getById('frame_size');
        var width  = parseInt(rect.style.width);
        var height = parseInt(rect.style.height);
        document.body.removeChild(rect);
        document.onmousemove = null;
        document.onmousedown = null;
        // Subtract the height of the black 'R' icon from the total
        // height to obtain the new whiteboard height
        height = height - 20;
        // Minimal check just to avoid point-like resize from inexpert
        // users. Current width is limited by the width of menu
        // buttons, current height is limited by the height of
        // whiteboard toolbox buttons
        if (width<600)
            width = 600;
        if (height<600)
            height = 600;
        var sizes = width+'-'+height;
        // Store the state of the signer object (because the page will
        // be reloaded)
        g['signer'].store();
        $query = {'mode':'resize', 'user':S['user'], 'client_id':S['client_id'],
                  'key':'sizes', 'value':sizes};
        send_post('index.php', $query);
    };
}

/*
 * Change the proportions of the inner division between the whiteboard
 * and the right side panel
 */
function inner_resize() {
    // Retrieve the style attributes of the div to be cloned
    var right_column = getById('right_column');
    var style = {
        'height': right_column.offsetHeight,
        'width':  right_column.offsetWidth,
        'top':  0,
        'left': 0};
    // Find the top left corner position with an iteration
    do {
        style['top']  += right_column.offsetTop;
        style['left'] += right_column.offsetLeft;
        right_column = right_column.offsetParent;
    } while (right_column != null);

    // Create the new movable column that will follow user's
    // pointer. That column is the left margin of a div cloned from
    // the rigth column
    var column = document.createElement('div');
    column.setAttribute('id', 'vertical_size');
    for (s in style)
        column.style[s] = style[s]+'px';
    document.body.appendChild(column);
    application_notify_message('Click again to resize the whiteboard');

    document.onmousemove = function(event) {
        event = event ? event : window.event;
        var column = getById('vertical_size');
        var right = parseInt(column.style.left) + parseInt(column.style.width);
        var new_width = (right - event.clientX);
        // Check conditions (not too tight, not too wide) and update
        if( (event.clientX < right - 150) &&
            (new_width < S['width'] - 150) ){
            column.style.left = event.clientX+'px';
            column.style.width = new_width+'px';
        }
    };
    document.onmousedown = function(event) {
        var side_w = parseInt(getById('vertical_size').style.width);
        document.body.removeChild(getById('vertical_size'));
        document.onmousemove = null;
        document.onmousedown = null;
        // Store the state of the signer object (because the page will
        // be reloaded)
        g['signer'].store();
        $query = {'mode':'resize', 'user':S['user'], 'client_id':S['client_id'],
                  'key':'side_w', 'value':side_w};
        send_post('index.php', $query);
    };
}

// right_resize can be called either from the user or automatically to
// show or hide the slides. The 'y' parameter can be a string ('mid'
// to show the slides, 'max' to hide them) or a number expressing the
// current mouse position (and so the desired position for the
// horizontal panel).
function right_resize(y) {
    switch(y) {
    case 'max':
        var top_height = S['svg_h'];
        var bottom_height = 0;
        break;
        // middle
    case 'mid':
        var top_height = S['svg_h']/2;
        var bottom_height = S['svg_h']/2;
        break;
    default:
        var div = getById('right_top');
        var div_top = 0;
        do {
            div_top += div.offsetTop;
            div = div.offsetParent;
        } while(div != null);
        var top_height = y - div_top;
        var bottom_height = S['svg_h'] - top_height;
        // Check conditions (too high, too low) to stop
        if((top_height < 70) || (bottom_height < 0))
            return;
        break;
    }
    // Set the new proportions between chat text output and chat text
    // input (compare with the server-side function chat_nodes into
    // markup_send.php)
    var margin = 10;
    var chat_inner_height = top_height - margin - 2;
    getById('text_output').style.height =
        parseInt(chat_inner_height*0.8)+'px';
    // Explorer can raise an error if this parsed int is negative, so
    // check the correspondance between the margin value and the
    // conditions above
    getById('text_input_t').style.height =
        parseInt(chat_inner_height*0.2 - margin)+'px';
    // Set the new proportions between chat ('right_top') and slides
    // ('right_bottom')
    getById('right_top').style.height    = top_height+'px';
    getById('right_bottom').style.height = bottom_height+'px';
}

function user_right_resize() {
    // first call, starting resize, click on the div
    if (document.onmousemove === null) {
        getById('horizontal_separator').style.background = 'navy';
        document.onmousemove = function(event) {
            event = event ? event : window.event;
            right_resize(event.clientY);
        };
        document.onmousedown = user_right_resize;
        application_notify_message('Click again on the same area to end resizing');
    } else {
	// Second call, ending resize, click everywhere
        getById('horizontal_separator').style.background = '';
        document.onmousedown = null;
        // I use a little interval so also when the user clicks on the
        // same panel, the second click doesn't starts again to resize
        setTimeout('document.onmousemove=null', 200);
    }
}

/* g['receiver'] and g['sender'] are the sender and receiver objects,
 * which manage the comunication with the server. The sender and the
 * receiver can be seen as parallel comunication channels */

g['receiver'] = {
    channel: null,
    cycle_timeout: 100,
    // Request id
    req_id: 1,
    // Id of the next update line to be received
    upd_id: 1,
    // Id of the first update that shouldn't be refreshed (changed by
    // receiver_handler), set to zero here and correctly initializated
    // by the first successful receiver_handler. This parameter allows
    // to distinguish between user updates which must be redone and
    // user updates which must be ignored
    refresh_id: 0,
    // Set to true when a refresh has been asked. It will be read by
    // receiver_handler before that a new request is sent, to change
    // the receiver status
    refresh: false
};

function receiver_send() {
    // Channel's 'send' should never return false here, because an
    // update request is done always after an old one has been
    // received. The only exception is when the user asks for a refresh
    if(g['receiver'].channel.send(['upd_id='+g['receiver'].upd_id]))
        g['receiver'].req_id++;
}

function receiver_timeout() {
    g['receiver'].channel.handle_timeout();
}

/*
 * Receiver handler: when the channel has received, process the
 * updates or handle the errors
 */
function receiver_handler() {
    var r = g['receiver'].channel.received();
    if (r === false)
	return;
    var error = r.getElementsByTagName('error').item(0);
    if (error === null) {
	var next_upd_id =
	    r.getElementsByTagName('next_upd_id').item(0).firstChild.data;
	next_upd_id = parseInt(next_upd_id);
        // If this is the first successful receiver_handler, set a
        // correct value for refresh_id
        if (g['receiver'].refresh_id===0) {
            g['receiver'].refresh_id = next_upd_id;
        }
	if (next_upd_id > g['receiver'].upd_id) {
	    g['receiver'].upd_id = next_upd_id;

	    // Retrieve the lines with their parameters
	    var madeByArray     = r.getElementsByTagName('madeby');
	    var upd_idArray     = r.getElementsByTagName('update_id');
	    var pageArray       = r.getElementsByTagName('page');
	    var objIdArray      = r.getElementsByTagName('objid');
	    var actionArray     = r.getElementsByTagName('action');
	    var parametersArray = r.getElementsByTagName('parameters');
	    var timeArray       = r.getElementsByTagName('time');        
	    /*
	     * Process data received from the server. We receive
	     * several arrays, same length, representing the
	     * columns of the database.
	     */
	    for (var i = 0; i < pageArray.length; i++) {
		var parameters = '';
		// Null 'firstChild' creates problems with IE. The
		// 'delete' operation had empty parameters before;
		// now it has 'delete' as parameters, so this
		// check could be avoided; i leave it for
		// robustness
		if (parametersArray[i].firstChild) {
		    // Extract parameters: par|par|...|par comes
		    // directly from the DB
		    parameters = parametersArray[i].firstChild.data.split('|');
		}
		var madeBy = madeByArray[i].firstChild.data;
		var upd_id = upd_idArray[i].firstChild.data;
		var page   = pageArray[i].firstChild.data;
		var objId  = objIdArray[i].firstChild.data;
		var action = actionArray[i].firstChild.data;
		var time   = timeArray[i].firstChild.data;
		// This log line is very useful - don't delete
		mylog('Update received: madeby '+madeBy
		      +' objid '+objId
		      +' action '+action
		      +' parameters '+parameters);
		if (action == 'chat') {
		    chatServerUpdate(madeBy, objId, parameters, time);
		    continue;
		}
		// All other actions act on the whiteboard: those
		// creating a new whiteboard element (path, line,
		// circle, polygon) and those for other actions
		// (move, delete, clear)
		// Actions that need to be "refreshed" will be
		// executed, but new actions will be executed
		// just when they come from different users
		// (except the "images", that get updated by
		// the server also for the current user)
		if (upd_id < g['receiver'].refresh_id ||
			madeBy != S['user'] || action == 'image'){
		    parameters = g['size_adapter'].convert(action,
							   parameters,
							   'local');
		    whiteboardServerUpdate(objId, page, action, parameters);
		}
	    }
	}
	// If the refresh flag has been activated, set the correct
	// parameters for the new request, where all the updates
	// will be asked from the first one
	if (g['receiver'].refresh) {
	    // Clear the whiteboard and chat
	    g['pages'].reset();
	    clearChat();
	    // Restart asking from the beginning
	    g['receiver'].refresh_id = g['receiver'].upd_id;
	    g['receiver'].upd_id = 1;
	    g['receiver'].refresh = false;
	}
    } else {
	var error = error.firstChild.data;
	switch(error) {
	case 'credentials':
	    // The most common credentials error could be an
	    // outdated server salt
	    logout('A security error occurred, please login again');
	    break;

	case 'whiteboard deleted':
	    logout('Logged out because the whiteboard has been deleted');
	    break;
	}
    }
    // At the end of a received response, start a timeout for a
    // new update request
    setTimeout(receiver_send, g['receiver'].cycle_timeout);
}

g['sender'] = {
    channel: null,
    // The buffer for updates to sent
    line_buff: [],
    // The id for the next update to avoid duplication on the server
    // side (must be initialized with a value coming from the server
    // side)
    send_id: null
};

/*
 * Add a line to the array that will be sent to the server. All
 * function parameters but 'action' are optional. The last param tells
 * if to immediately send the update (as it's desired usually) or not.
 */
function sender_add(action, parameters, varidObj, async){
    // for actions: clear, refresh, resize, slides
    if (varidObj == undefined)
        varidObj = action;
    // for actions: clear, refresh, delete
    if (parameters == undefined || parameters == []) {
        parameters = action;
    } else{
        parameters = g['size_adapter'].convert(action, parameters, 'global');
        parameters = parameters.join('|');
    }
    // The order of these fields is relevant server side (function
    // write, file updates.php)
    var line = g['pages'].current+':'+varidObj+':'+action+':'+parameters;
    g['sender'].line_buff.push(line);
    mylog('Added line: ' + line);
    if(async==undefined)
        sender_send();
}

function sender_send() {
    var data = g['sender'].line_buff.join(';');
    var chan_params = ['send_id='+g['sender'].send_id, 'data='+data];
    // Change state variables just if the send operation succeeds
    if(g['sender'].channel.send(chan_params))
        g['sender'].line_buff = [];
    // If the send operation fails, this is because the channel object
    // is waiting for an old request. This update will remain into the
    // buffer and will be sent by sender_handler
}

function sender_timeout(){
    g['sender'].channel.handle_timeout();
}

function sender_handler(){
    var response = g['sender'].channel.received();
    if (response === false)
	return;
    // The send_id must be incremented here, because also requests
    // with errors have been read by the server. The usefulness of
    // the sender id is not to identify successful request, but to
    // avoid duplications
    g['sender'].send_id++;
    var error = response.getElementsByTagName('error').item(0);
    if (error === null) {
	// If still have data to send (added while waiting the server
	// response)
	if (g['sender'].line_buff.length > 0)
	    sender_send();
    } else {
	// Handle errors
	if (error==='duplicated') {
	    // This was a request duplicated due to a timeout
	    // retry. The request has been ignored by the server
	    // side, and we too must ignore its consequences
	    g['sender'].send_id--;
	} else if (/image/.test(error)) {
	    // Image errors are in the form:
	    // 'image-objid-page' (see server side function write)
	    user_help('img_err');
	    // Delete the temporary image
	    var position = error.split('-');
	    whiteboardServerUpdate(position[1], position[2], 'delete')
	}
    }
}

/*
 * Object that converts between local sizes (in pixel) and global
 * sizes (which can go from 0 to 100)
 */
g['size_adapter'] = {
    // parameters where sizes can be changed directly, ordered by
    // action. For each parameter, its direction is specified
    direct: {
        'line'   :{4:'x', 5:'y', 6:'x', 7:'y'},
        'rect'   :{4:'x', 5:'y', 6:'x', 7:'y'},
        'circle' :{4:'x', 5:'y', 6:'x'},
        'text'   :{2:'x', 3:'y'},
        'link'   :{2:'x', 3:'y'},
        'image'  :{2:'x', 3:'y', 5:'x', 6:'y'},
        'move'   :{0:'x', 1:'y'}
    },
    // orientation can be 'x' or 'y'
    // result can be 'global' or 'local'
    convert_single: function(value, orientation, result){
        if (orientation === 'x')
            var size = this.width;
        else
            var size = this.height;
        if (result === 'global')
            var ratio = 100/size;
        else
            var ratio = size/100;
        var number = value * ratio;
        // Round the number to 1 decimal fixed notation to reduce the
        // overall request size (the value is an empirical compromise
        // between space reduction and coordinate distortion)
        return number.toFixed(1);
    },

    init: function(svg_w, svg_h){
        this.width  = svg_w;
        this.height = svg_h;
    },
    // result can be 'global' or 'local'
    convert: function(action, parameters, result){
        if (this.direct[action] !== undefined){
            var orientations = this.direct[action];
            for (p in parameters){
                if (orientations[p] !== undefined){
                    parameters[p] =
                        this.convert_single(parameters[p],
                                            orientations[p],
                                            result);
                }
            }
        } else if (action == 'polygon' || action == 'polyline'){
            var points = parameters[4];
            var coords = points.split(' ');
            for (c in coords){
                single_coord = coords[c].split(',');
                single_coord[0] = this.convert_single(single_coord[0], 'x', result);
                single_coord[1] = this.convert_single(single_coord[1], 'y', result);
                coords[c] = single_coord.join(',');
            }
            parameters[4] = coords.join(' ');
        } else if (action == 'path'){
            var d = parameters[4];
            var coords = d.split(' L ');
            // Remove the M from the first coord
            coords[0] = coords[0].slice(1);
            for (c in coords){
                single_coord = coords[c].split(',');
                single_coord[0] = this.convert_single(single_coord[0], 'x', result);
                single_coord[1] = this.convert_single(single_coord[1], 'y', result);
                coords[c] = single_coord.join(',');
            }
            coords[0] = 'M'+coords[0];
            parameters[4] = coords.join(' L ');
        }
        return parameters;
    }
};
