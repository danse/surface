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
// $Id: shapes.js 132 2010-12-13 10:53:17Z s242720-studenti $

g['shape_generator'] = {
    // default values (some of these values are ignored by some
    // shapes, depending on each shape's attribute list). These can be
    // changed by the user.
    default_values: {
        'stroke': '#000000',
        'fill': '#000000',
        'stroke-width': 5,
        'opacity': 1,
        'fill-opacity': 0.0,
        // unlike the others, scaling-factor is not an SVG attribute
        'scaling-factor': 100
    },
    generate_shape: function(string, page){
        var shape;
        if(string=='move'||
           string=='edit'||
           string=='delete'||
           string=='select'||
           string=='clear'||
           string=='refresh')
            shape = not_active_shape();
        else{
            switch(string){
            case 'line'      : shape = line()              ; break;
            case 'rect'      : shape = rect()              ; break;
            case 'circle'    : shape = circle()            ; break;
            case 'path'      : shape = path()              ; break;
            case 'multipath' : shape = path('multipath')   ; break;
            case 'polygon'   : shape = polygon()           ; break;
            case 'polyline'  : shape = polygon('polyline') ; break;
            case 'text'      : shape = text()              ; break;
            case 'link'      : shape = link()              ; break;
            case 'image'     : shape = image()             ; break;
            }
            // If generate_shape is called with a page value (a server
            // update), set the page. Otherwise (an user tool
            // activation), the page will be set when the shape is
            // started the first time (open_shape)
            if(page != undefined)
                shape.page = page;
            // Assign default values
            for (v in this.default_values)
                shape[v] = this.default_values[v];
        }
        return shape;
    }
}

/* base class from which all the shapes are derived */
function shape(type, att){
    var object = {};
    object.type = type;

    // The colors are common attributes between all shapes
    var colors = ['stroke', 'fill'];
    object.att = colors.concat(att);

    object.open = false;
    object.edit = false;
    // active is used by the 'edit' tool. An edited shape remains on
    // the global scope, but it is unactivated, so the upstream code
    // understands that it can edit a new shape.
    object.active = true;

    object.open_shape = function(){
        this.page = g['pages'].current;
        this.open = true;
    };
    object.close_shape = function(){
        this.open = false;
        // If this was an edit shape, make it unusable to allow to
        // click on a new shape
        if (this.edit)
            this.active = false;
    };
    // create_group sets the 'id' and 'group' attributes of the shape
    // object. If an 'id' is given to create_group, the group is
    // already existent, otherwise, a new id will be generated.
    object.create_group = function(id){
        if(id)
            this.id = id;
        else
            // Generate unique object id
            this.id = g['idObj'].get_new();

        this.group = document.getElementById(id)
        if(!this.group){
            // Create the <g> element
            this.group = document.createElementNS(svgns, 'g');
            this.group.setAttribute('id', this.id);
            // Append the group to the page assigned by the
            // page_generator
            g['pages'].append(this.group, this.page);
        }
    };
    // Automatically create an SVG element, when its type and
    // attributes are like the shape type and attributes
    object.create_element = function(){
        this.element = document.createElementNS(svgns, this.type);
        for(a in this.att)
            this.element.setAttribute(this.att[a], this[this.att[a]]);
        this.group.appendChild(this.element);
    };
    // start_shape, end_shape and server_create are built using the
    // functions above. The most of the times the shapes will use
    // start_shape, end_shape, and server_create, but sometimes a
    // finer control is useful
    object.start_shape = function(id){
        this.open_shape();
        this.create_group(id);
        this.create_element();
    };
    object.end_shape = function(){
        var params = [];
        for(a in this.att)
            params.push(this.element.getAttribute(this.att[a]));
        // Add the element in the array that will be sent to the server.
        sender_add(this.type, params, this.id);
        this.close_shape();
    };
    object.server_create = function(par, id){
        for(a in this.att)
            this[this.att[a]] = par[a];
        this.create_group(id);
        this.create_element();
    };

    /* Retrieve attributes from the edited object */
    object.copy_shape = function(target){
        for(a in this.att)
            this[this.att[a]] = target.getAttribute(this.att[a]);

        // Retrieve the translation values applied to the object (by
        // move actions). These values can be added to the attributes
        // read from the old shape.
        var parentNode = target.parentNode;
        this.offset_x = Number(parentNode.getCTM()['e']);
        this.offset_y = Number(parentNode.getCTM()['f']);

        this.edit = true;
    };

    /* following functions (show_text_area, onkeyup) will be used just
     * by the shapes: text, link, image, which require textual input
     * from the user. After receiving the textual input, shapes are
     * created as if the data came from the server. Each of these
     * classes must define a 'text' attribute to receive the user
     * input */
    object.show_text_area = function(x, y){
        // close_shape is at the end of onkeyup
        this.open_shape();
        // Set the coordinates of the textarea and display it (or just
        // move it to this point if it is visible already)
        var text_area = getById('textinput');
        text_area.style.left = x + g['x_offset'] + 'px';
        text_area.style.top  = y + g['y_offset'] + 'px';
        text_area.style.display = 'inline';
        text_area.focus();
    };
    object.onkeyup = function(event){
        key = cross_which(event);
        // This function should continue only if the user pressed just
        // "enter" (and not "shift+enter")
        if (key == 13 && !cross_shift(event)){
            var textarea = getById("textinput");
            // Hide the textarea and get her value
            textarea.style.display = 'none';
            var text = textarea.value;
     
            // Get rid of all whitespaces and newlines before and after the text
            text = text.replace(/(^[ \n\r]+)|([ \n\r]+$)/g, '');
            if (text != ''){
                // Escape the text for separators like :, |, etcetera. It must
                // be unescaped client side by server_create functions, and
                // server side where needed. The escape is done two times,
                // because it is unescaped once by the server while reading the
                // POST ajax request, whose mime type is
                // "application/www-form-urlencoded"
                this.text = escape(escape(text));
                if (this.type === 'image'){
                    // Each new image update sent to the server carries
                    // the scale factor for the image into the 'stroke'
                    // slot of the update (which would be unused
                    // otherwise)
                    this['stroke'] = this['scaling-factor'];
                }
                // Build the parameter array
                var par = [];
                for(a in this.att)
                    par.push(this[this.att[a]]);
                // Show the shape client side. For this kind of shapes
                // the function for locally created shapes is the same
                // of that for remotely created shapes; we just omit
                // the 'id' so that a new one will be created
                this.server_create(par);
                // send server update
                sender_add(this.type, par, this.id);
            }
            textarea.value = '';
            this.close_shape();
        }
    };

    // This methods will be overriden by each specific shape, but for
    // shapes that don't use some of them, an empty definition is the
    // fallback
    object.mousedown = function(x, y){};
    object.mousemove = function(x, y){};
    object.mouseup   = function(){};
    return object;
}

/* shape_introduction:

  Here follow the different shapes derived from 'shape'. There are two
  kind of shapes, with similar behaviors within their groups:

  - dynamic shapes like line, rect, circle, polygon, path, etcetera:
    they have a corresponding svg element which must be updated before
    that the shape is finished. Usually they:

      - start the shape

      - update the state of the svg element responding to the user
        actions

      - end the shape and send the result to the server

  - static shapes (which use the textarea) like text, link and image:
    they collect the user input and then build the shape and send the
    result to the server. The creation of a shape on the part of an
    user, in this case, is similar to the creation of the shape by the
    part of the server (the whole shape is created in the same
    moment).

 */

/* A not active shape does nothing, it is not usable, its purpose is
 * just telling the caller that this shape is unusable */
function not_active_shape(){
    var object = shape('', []);
    object.active = false;
    return object;
}

function line(){
    var att = ['opacity','stroke-width','x1','y1','x2','y2'];
    var object = shape('line', att);

    object.mousedown = function(x, y){
        if (this.open)
            return;
        if (this.edit){
            var vertexes = new Array();
            var x1 = parseInt(this.x1) + this.offset_x;
            var y1 = parseInt(this.y1) + this.offset_y;
            var x2 = parseInt(this.x2) + this.offset_x;
            var y2 = parseInt(this.y2) + this.offset_y;
            vertexes[0] = {x: x1, y: y1};
            vertexes[1] = {x: x2, y: y2};
            // The fixed point of the line is the farthest from the
            // mouse
            var fixed;
            if (nearest_vertex(vertexes, {x:x, y:y}) == 0)
                fixed = 1;
            else
                fixed = 0;
            this.x1 = parseInt(vertexes[fixed]['x']);
            this.y1 = parseInt(vertexes[fixed]['y']);
        }
        else{
            this.x1 = x;
            this.y1 = y;
        }
        this.x2 = x;
        this.y2 = y;
        this.start_shape();
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        // Set the end point of the line        
        this.element.setAttribute('x2', x);
        this.element.setAttribute('y2', y);
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

function circle(){
    var att = ['fill-opacity','stroke-width','cx','cy','r'];
    var object = shape('circle', att);

    object.mousedown = function(x, y){
        if (this.open)
            return;
        if(this.edit){
            this.cx = parseInt(this.cx) + this.offset_x;
            this.cy = parseInt(this.cy) + this.offset_y;
        }
        else{
            this.cx = x;
            this.cy = y;
        }
        this.r = 0;
        this.start_shape();
        // Just to update the aspect of the edited shape
        if(this.edit)
            this.mousemove(x, y);
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        // Compute and set the radius of the circle        
        var dx = this.cx - x;
        var dy = this.cy - y;
        var r = Math.sqrt(dx*dx + dy*dy);
        Math.round(r);
        this.element.setAttribute('r', r);
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

function rect(){
    var att = ['fill-opacity','stroke-width','x','y','width','height'];
    var object = shape('rect', att);

    object.mousedown = function(x, y){
        if (this.open)
            return;
        if(this.edit){
            //find  rect vertexes from the svg element attributes
            this.x = parseInt(this.x) + parseInt(this.offset_x);
            this.y = parseInt(this.y) + parseInt(this.offset_y);
            var vertexes =
                Array(// 0 high left
                      {'x':this.x,
                       'y':this.y},
                      // 1 low left
                      {'x':this.x,
                       'y':parseInt(this.y)+parseInt(this.height)},
                      // 2 low right
                      {'x':parseInt(this.x)+parseInt(this.width),
                       'y':parseInt(this.y)+parseInt(this.height)},
                      // 3 high right
                      {'x':parseInt(this.x)+parseInt(this.width),
                       'y':this.y});
            var n = nearest_vertex(vertexes, {x:x, y:y})
                var starting = [2, 3, 0, 1];
            this.x = parseInt(vertexes[starting[n]]['x']);
            this.y = parseInt(vertexes[starting[n]]['y']);
        }
        else{
            this.x = x;
            this.y = y;
        }
        this.width = 0;
        this.height = 0;
        this.start_shape();
        // Just to update the aspect of the edited shape
        if(this.edit)
            this.mousemove(x, y);
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        // Calculate the position and dimensions based on which quadrant
        // we are

        var width  = x - this.x;
        if (width < 0){
            width = -width;
            this.element.setAttribute('x', x);
        }
        this.element.setAttribute('width', width);

        var height = y - this.y;
        if (height < 0){
            height = -height;
            this.element.setAttribute('y', y);
        }
        this.element.setAttribute('height', height);
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

/* the difference between path and multipath is that multipath doesn't
 * do start_shape on mousedown, but only on shape creation */
function path(type){
    var att = ['opacity', 'stroke-width', 'd'];
    var object = shape('path', att);
    // The group is created here (once for all strokes) for mutipaths,
    // and into onmousedown for single paths
    if(type=='multipath'){
        object.create_group();
        object.single = false;
    }
    else
        object.single = true;

    object.mousedown = function(x, y){
        if (this.open)
            return;
        this.d = 'M'+x+','+y;
        this.fill = 'none';
        // This is like a start_shape function, but create the group
        // just if this isn't a multipath
        this.open_shape();
        if(this.single)
            this.create_group();
        this.create_element();
    };
    object.mousemove = function(x, y){
        if (!this.open)
            return;
        var d = this.element.getAttribute('d');
        d += ' L '+x+','+y;
        this.element.setAttribute('d', d);
        // firefox/native truncates the received updates, so don't let
        // this field become too long. This way, at least the user
        // knows what can be seen by everyone. I measured that the
        // field was truncated to 4096 chars. The final size of the
        // field also depends by the conversions made by the
        // size_adapter object (application.js), so even with this
        // check the final path could lose some of his last points,
        // but this is not a big problem
        if (d.length>4000)
            this.mouseup();
    };
    object.mouseup = function(){
        if (!this.open)
            return;
        this.end_shape();
    };
    return object;
}

/* polygons and polylines differ just into the type of the element to
 * be created, and the presence or not of the fill color. If the
 * function is called without arguments, it starts a polygon,
 * otherwise, it starts a polyline */
function polygon(type){
    if(!type){
        type = 'polygon';
        var att = ['fill-opacity', 'stroke-width', 'points'];
    }
    else{
        type = 'polyline';
        var att = ['opacity', 'stroke-width', 'points'];
    }
    var object = shape(type, att);

    // To store the last mouse coordinates, to recognize a double click
    object.x = -1;
    object.y = -1;

    /* A single click adds a point, a double click closes the
     * polygon */
    object.mousedown = function(x, y){
        // If the polygon is open, add a point or close the polygon
        if(this.open){
            // If this point is the same of the previous, close the
            // polygon (this.x and x are strange objects in the svgweb
            // environment, so they must be converted)
            if(parseInt(this.x) == parseInt(x) &&
               parseInt(this.y) == parseInt(y))
                this.end_shape();
            // If this point is different from the previous, add it to the
            // points and vertexes list and store mouse coordinates to wait
            // a double click
            else{
                this.points += ' '+x+','+y;
                this.vertexes.push({'x':x, 'y':y});
                object.x = x;
                object.y = y;
            }
        }
        // If the polygon isn't open, start a new one
        else{
            // An array to hold the polygon points
            this.vertexes = new Array();
            if(this.edit){
                // Transform points string to an array, adding the
                // translation to each point
                var pairs = this.points.split(' ');
                // Chrome's native renderer has a different syntax for points,
                // they are all divided just by whitespaces as in "2 3 2 4 5 6"
                if (sniff() == 'chrome' && svgweb.getHandlerType() == 'native')
                    // pairs.length is always even here
                    for(var i=0; i<pairs.length-1; i=i+2){
                        var vx = parseInt(pairs[i]  ) + this.offset_x;
                        var vy = parseInt(pairs[i+1]) + this.offset_y;
                        this.vertexes.push({'x': vx, 'y': vy});
                    }
                // For all other configurations, points are pairs of comma
                // separated values separated by whitespaces as in "2,3 2,4 5,6"
                else
                    for(var i=0; i<pairs.length; i++){
                        pair = pairs[i].split(',');
                        var vx = parseInt(pair[0]) + this.offset_x;
                        var vy = parseInt(pair[1]) + this.offset_y;
                        this.vertexes.push({'x': vx, 'y': vy});
                    }
                // Store the nearest index
                this.edit_nearest =
                    nearest_vertex(this.vertexes, {'x':x, 'y':y});
            }
            // If not editing
            else{
                this.points = x+','+y;
                this.vertexes.push({'x':x, 'y':y});
                if(this.type=='polyline')
                    this.fill = 'none';
            }
            this.start_shape();
            if(this.edit)
                this.mousemove(x, y);
        }
    };
    object.mousemove = function(x, y){
        if(!this.open)
            return;
        if(this.edit){
            this.vertexes[this.edit_nearest] = {'x':x, 'y':y};
            var points = this.points_from_vertexes();
        }
        else
            var points = this.points+' '+x+','+y;
        this.element.setAttribute('points', points);
    };
    // This shapes ends on mouseup when it is edited, but it ends in a
    // special case of mousedown when it's created
    object.mouseup = function(){
        if(this.edit)
            this.end_shape();
    };
    // Due to chrome's different point syntax, end_shape must be
    // overridden
    object.end_shape = function(){
        var params = [];
        for(a in this.att){
            if(this.att[a]=='points' && sniff() == 'chrome'
               && svgweb.getHandlerType() == 'native')
                params.push(this.points_from_vertexes());
            else
                params.push(this.element.getAttribute(this.att[a]));
        }
        // Add the element in the array that will be sent to the server.
        sender_add(this.type, params, this.id);
        this.close_shape();
    };
    object.points_from_vertexes = function(){
        var points = '';
        for(i=0; i<this.vertexes.length; i++)
            points += this.vertexes[i]['x'] +','+ this.vertexes[i]['y']+' ';
        // Get rid of trailing white space
        points = points.slice(0, -1);
        return points;
    };
    return object;
}

/* the following classes (text, link, image) must override the
 * server_create method, because they use a special way to build the
 * svg object. Furthermore, they use the server_create method even
 * when it's this user which is creating the shape (See
 * 'shape_introduction' above)*/

/*
  Structure of a text shape:
  <g id="1_2_3">
   <text fill="#abc123" x="5" y="30">
    <tspan x="5" y="30">first row</tspan>
    <tspan x="5" y="40">second row</tspan>
   </text>
  <g>
*/
function text(){
    var att=['x', 'y', 'text'];
    var object = shape('text', att);

    // Override the function copy_shape mantaining the most part of
    // his code
    object.parent_copy_shape = object.copy_shape;
    object.copy_shape = function(target){
        this.parent_copy_shape(target);
        // Retrieve the 'text' and put it into the textarea
        var rows = [];
        var childs = target.childNodes;
        for (var n=0; n<childs.length; n++)
            // Between the child nodes, there are a lot of nodes
            // which are not useful (comments and any kind of
            // oddity), so take only the tspans
            if (childs[n].nodeName=='tspan')
                // Read the data of the textnode which is the first
                // child of the <tspan>
                rows.push(childs[n].childNodes[0].data);
        getById('textinput').value = rows.join('\n');
    };

    object.mousedown = function(x, y){
        this.x = x;
        this.y = y;
        object.show_text_area(x, y);
    };

    object.server_create = function(par, id){
        this.create_group(id);
        var fill = par[1];
        var x    = parseInt(par[2]);
        var y    = parseInt(par[3]);
        if (id === undefined)
            //client call
            var content = unescape(unescape(par[4]));
        else
            //server call
            var content = unescape(par[4]);
        // Create the element (can't use create_element because this case
        // is not standard)
        this.element = document.createElementNS(svgns, 'text');
        sa(this.element, {'fill': fill, 'x':x, 'y':y});
        this.group.appendChild(this.element);
        var rows = content.split('\n');
        // Process rows to create a <tspan> for each row.  The vertical
        // coordinate ('y') will be incremented with rows
        for (var r=0; r < rows.length; r++){
            var tspan = document.createElementNS(svgns, 'tspan');
            // I found that characters like '<' are converted to their
            // corresponding HTML entities. I don't know if it is a
            // feature of the svgweb library or it is normal for
            // createTextNode. Differently, chat text is encoded to
            // HTML entities by the server-side code
            var tnode = document.createTextNode(rows[r], true);
            tspan.appendChild(tnode);
            tspan.setAttribute('x', x);
            tspan.setAttribute('y', y);
            this.element.appendChild(tspan);
            y = y + (g['fontSize']+1);
        }
        // In svgweb, text nodes can't inherit handlers from root like
        // other nodes do, so we have to add the handlers here
        if (svgweb.getHandlerType() == 'flash'){
            this.element.addEventListener('mousedown', handleMouseDown, false);
            this.element.addEventListener('mouseup', handleMouseUp, false);
            this.element.addEventListener('mousemove', handleMouseMove, false);
        }
    };
    return object;
}

/*
  Structure of a link shape:
  <g id="1_2_3">
   <text fill="#0000CD" x="5" y="30">
    <tspan visibility="hidden"></tspan>
    http://www.myurl.com
   </text>
  <g>
*/
function link(){
    var att=['x', 'y', 'text'];
    var object = shape('link', att);

    // Override the function copy_shape mantaining the most part of
    // his code
    object.parent_copy_shape = object.copy_shape;
    object.copy_shape = function(target){
        this.parent_copy_shape(target);
        // Read the url and put it into the textarea
        // (text.textnode.data)
        getById('textinput').value =
        target.childNodes[1].data;
    }

    object.mousedown = function(x, y){
        this.x = x;
        this.y = y;
        object.show_text_area(x, y);
    };

    object.server_create = function(par, id){
        this.create_group(id);
        var x = par[2];
        var y = par[3];
        if (id === undefined)
            //client call
            var url = unescape(unescape(par[4]));
        else
            //server call
            var url = unescape(par[4]);
        // Create the element (can't use create_element because this case
        // is not standard)
        this.element = document.createElementNS(svgns, 'text');
        sa(this.element, {'fill': '#0000CD', 'x':x, 'y':y});
        this.group.appendChild(this.element);
        // First add an hidden tspan to distinguish the link from plain
        // text (this is read into handleMouseDown, branch "select")
        var tspan = document.createElementNS(svgns, "tspan");
        tspan.setAttribute('visibility', 'hidden');
        this.element.appendChild(tspan);
        // Add url content
        var tnode = document.createTextNode(url,true);
        this.element.appendChild(tnode);
        // In svgweb, text nodes can't inherit handlers from root like
        // other nodes do, so we have to add the handlers here
        if (svgweb.getHandlerType() == 'flash'){
            this.element.addEventListener('mousedown', handleMouseDown, false);
            this.element.addEventListener('mouseup', handleMouseUp, false);
            this.element.addEventListener('mousemove', handleMouseMove, false);
        }
    };

    return object;
}

function image(){
    var att = ['x', 'y', 'text', 'width', 'height'];
    var object = shape('image', att);

    // These will be the sizes (in pixels) of the temporary
    // image. They will be sent to the server but ignored
    object.width  = 90;
    object.height = 90;

    // Override the function copy_shape mantaining the most part of
    // his code
    object.parent_copy_shape = object.copy_shape;
    object.copy_shape = function(target){
        this.parent_copy_shape(target);
        // Retrieve the url and put it into the textarea
        getById('textinput').value =
        target.getAttributeNS(xlinkns, 'href');
    }

    object.mousedown = function(x, y){
        this.x = x;
        this.y = y;
        object.show_text_area(x, y);
    };

    object.server_create = function(par, id){
        this.create_group(id);
        // the 'image' shape distinguishes between a client creation (with
        // temporary image) and a server one, while for 'text' and 'link'
        // shapes server creation and client creation are the same
        if(id)
            this.server = true;
        else
            this.server = false;
        // This could be a server image ready to substitute an user's
        // temporary image. In this case, don't create a new image but
        // change the attributes of the existing one
        if(this.group.childNodes[0])
            this.element = this.group.childNodes[0];
        else
            this.element = document.createElementNS(svgns, this.type);
        // Set attributes taking care of xlink:href, which requires
        // setAttributeNS
        for(a in this.att){
            if(this.att[a]=='text'){
                if(this.server)
                    this.element.setAttributeNS(xlinkns, 'xlink:href',
                                                unescape(par[a]));
                else
                    this.element.setAttributeNS(xlinkns, 'xlink:href',
                                                'images/image_wait.png');
            }
            else
                this.element.setAttribute(this.att[a], par[a]);
        }
        this.group.appendChild(this.element);
    };

    return object;
}

// Returns the index of the point into the array which is nearest to
// the given point. This is used by shapes line, rect and poly to find
// the vertex which is to be moved
function nearest_vertex(vertexes, point){
    var nearest = 0;
    var diffx = parseInt(vertexes[nearest]['x']) - parseInt(point['x']);
    var diffy = parseInt(vertexes[nearest]['y']) - parseInt(point['y']);
    var nearest_dist = Math.sqrt((diffx*diffx) + (diffy*diffy));
    var dist;
    for(i=1; i<vertexes.length; i++){
        diffx = parseInt(vertexes[i]['x']) - parseInt(point['x']);
        diffy = parseInt(vertexes[i]['y']) - parseInt(point['y']);
        dist = Math.sqrt((diffx*diffx) + (diffy*diffy));
        if(dist<nearest_dist){
            nearest = i;
            nearest_dist = dist;
        }
    }
    return nearest;
}
// $Id: whiteboard.js 113 2010-11-10 12:18:41Z s242720-studenti $


/* Constants */

// Size of the Font
g['fontSize'] = 15;

/* Initial state */

// Initial tool
g['tool'] = 'move';
// Global variable holding the object currently moved by the user
g['moving_object'] = null;
// Useful for 'move' and 'delete'. Set true for the mousedown event,
// set false for the mouseup event
g['active_tool'] = false;
// Initial mouse position, useful for actions which require a
// difference of two positions like 'move'
g['p'] = {x:0, y:0};

/* Description for variables initialized elsewhere */

// Canvas offsets, for firefox native svg reader, that takes absolute
// mouse position from events. Initialized by update_canvas_offsets
g['x_offset'];
g['y_offset'];
// Reference to the HTML element corresponding to the last pressed
// button. Initialized by initWhiteboard
g['pressedButton'];

function initWhiteboard(){
    /* Add event hanlers. For this purpose I use 'addEventListener'
     * because this method is well supported by svgweb library
     * (instead of using 'addAttribute' or writing event handlers
     * directly into html) */
    var svg_root = getById('svg_root');
    svg_root.addEventListener('mousedown', handleMouseDown, false);
    svg_root.addEventListener('mouseup'  , handleMouseUp  , false);
    svg_root.addEventListener('mousemove', handleMouseMove, false);

    update_canvas_offsets();

    // Creating the initial page
    g['pages'].init();

    // Initial shape and initial additional panel. These will be
    // changed within handleTool, but must have an initial value
    g['shape'] = g['shape_generator'].generate_shape(g['tool']);
    g['additional_panel'] = '';
    // Settings related to the initial tool (build a fake event)
    handleTool({target:getById(g['tool']+'_button')});
}

g['pages'] = {
    // 'current' could be read from the outside
    current: 0,
    // An array containing references to all page elements
    index: {},
    /* All public methods */
    init: function(){
        this.add(this.current);
        this.index[this.current].setAttribute('style', '');
    },
    // Add the page with page number 'n'
    add: function(n){
        var page = document.createElementNS(svgns, 'g');
        this.index[n] = page;
        page.setAttribute('style', 'display:none');
        page.setAttribute('id', 'g'+n);
        getById('whiteboard_g').appendChild(page);
    },
    // Append a node to a page
    append: function(node, i){
        if(i == undefined)
            i = this.current;
        if(this.index[i] == undefined)
            this.add(i);
        this.index[i].appendChild(node);
    },
    // Remove a node from a page
    remove: function(node, page){
        if(page == undefined)
            page = this.current;
        this.index[page].removeChild(node);
    },
    // Remove all nodes on a page keeping the page
    clear: function(i){
        if(i == undefined)
            i = this.current;
        if(this.index[i] != undefined){
            var page = this.index[i];
            while (page.hasChildNodes())
                page.removeChild(page.lastChild);
        }
    },
    // Remove all nodes on all pages removing also the pages
    reset: function(){
        for(i in this.index)
            getById('whiteboard_g').removeChild(this.index[i]);
        this.index = {};
        this.init();
    },
    // swap, up, down: methods to change the current page
    swap: function(n){
        if(n != this.current){
            if(this.index[n]==undefined)
                this.add(n);
            this.index[this.current].setAttribute('style', 'display:none');
            this.index[n].setAttribute('style', '');
            getById('page_input').value = n;
            this.current = n;
        }
    },
    up: function(){
        if(this.current<99)
            this.swap(this.current+1);
    },
    down: function(){
        if(this.current>0)
            this.swap(this.current-1);
    }
};

// Some objects (like text) are nested inside others while firing
// handlers, so sometimes it is necessary to climb up the hierarchy to
// find the containing group ('g') tag, which is needed for moving and
// deleting operations. What is the exact child that fires an handler
// can depend also on the renderer in use.
function find_group(element){
    while(element.nodeName != 'g'){
        mylog('nested object, climbing up hierarchy...');
        element = element.parentNode;
    }
    return element;
}

/*
 * This function must be called on the whiteboard initialization and
 * whenever a movement of the canvas on the page occurs (not a
 * movement for the scroll, that is handled differently), to update
 * svg root's x and y coordinates that are needed by some browsers to
 * correctly place mouse events on the canvas
 */
function update_canvas_offsets(){
    // Set initial svg canvas offset
    var container = getById('svg_container');
    var top  = 0;
    var left = 0;
    do{
        top  += container.offsetTop;
        left += container.offsetLeft;
        container = container.offsetParent;
    }
    while(container != null);
    g['x_offset'] = left;
    g['y_offset'] = top;
}

/*
 * Compute a skew on a pair of coordinates, adding effects of
 * scrolling and adding the offsets of the svg root node, depending on
 * the renderer and the kind of coordinates. See "Coordinates within
 * the svg canvas" into the developer guide
 */
function skew(coords, pointer){
    // Look upon the page scroll. By default, don't apply scroll (this
    // applies to firefox/flash, and chrome non pointer coordinates)
    var X = coords.x;
    var Y = coords.y;
    if (svgweb.getHandlerType() == 'native'){
        // Firefox/native
        if (sniff() == 'firefox'){
            X = X + window.pageXOffset - g['x_offset'];
            Y = Y + window.pageYOffset - g['y_offset'];
        }
        // Crome/native: apply scroll just to pointer coordinates
        else
            if (pointer){
                X = X + window.pageXOffset - g['x_offset'];
                Y = Y + window.pageYOffset - g['y_offset'];
            }
    }
    return {'x': X, 'y': Y};
}

/*
 * Get mouse coordinates inside the svg canvas
 */
function mouseCoordsSvg(evt) {
    return skew({x:evt.clientX, y:evt.clientY}, true);
}

/*
 * Handler of the onclick event on the toolbox buttons (for colors see
 * handleStroke, handleFill)
 */
function handleTool(event){
    // Don't change the button until the user hasn't finished his draw
    if(g['shape'].open || g['active_tool']){
        user_help('close_draw');
        return;
    }
    var target = cross_target(event);
    // take the element type from attributes of the buttons
    var el = target.getAttribute('value');
    var el = target.getAttribute('value').toLowerCase();
    if(el=='<'){
        g['pages'].down();
        return;
    }
    if(el=='>'){
        g['pages'].up();
        return;
    }
    if(el=='clear'){
        g['pages'].clear();
        sender_add('clear');
        return;
    }
    // Clicking on the 'path' button switches between single path and
    // multipath
    if(el == 'path'){
        if(g['tool'] == 'path')
            g['tool'] = 'multipath';
        else
            g['tool'] = 'path';
    }
    else
        g['tool'] = el;

    // Unselect the old pressed button if necessary
    if (g['pressedButton'])
        g['pressedButton'].className = 'draw_button';
    // Highlight the new pressed button. The 'multipath' case has a
    // special style
    g['pressedButton'] = getById(target.id);
    if(g['tool']=='multipath')
        g['pressedButton'].className = 'draw_button_special';
    else
        g['pressedButton'].className = 'draw_button_pushed';

    show_additional_panel(g['tool']);
    // Set the new shape, if one
    g['shape'] = g['shape_generator'].generate_shape(g['tool']);
    // Show a short tip about the current tool
    user_help(g['tool']);
    // Sometimes the canvas offsets could be outdated due to little
    // recenterings of the whiteboard. This is an additional
    // (redundant) measure to make sure that canvas offsets are fine
    // before starting to draw
    update_canvas_offsets();
}

function handlePageInput(event){
    var key = cross_which(event);
    if(key == 13){
        var target = cross_target(event);
        g['pages'].swap(parseInt(target.value));
    }
}

/*
 * Show an additional panel with other input fields, if the tool (or
 * shape) needs one
 */
function show_additional_panel(tool){
    var new_panel = '';
    switch(tool){
    case 'polyline':
    case 'line':
    case 'path':
    case 'multipath':
        new_panel = 'open_shape_style';
        break;
    case 'polygon':
    case 'rect':
    case 'circle':
        new_panel = 'closed_shape_style';
        break;
    case 'image':
        new_panel = 'image_style';
        break;
    }
    if(g['additional_panel'] != new_panel){
        if(g['additional_panel'] != '')
            // Hide old panel
            getById(g['additional_panel']).className = 'hidden';
        if(new_panel != ''){
            var panel = getById(new_panel);
            // Show the new panel
            panel.className = 'new_panel';
        }
        // Save changes
        g['additional_panel'] = new_panel;
    }
}

/*
 * Handler of the onmousedown event.
 */
function handleMouseDown(evt)
{
    mylog('Started handleMouseDown with target nodeName '+evt.target.nodeName);
    // With svgweb, mousedown on text elements causes also mousedown on
    // the "svg" node on the back, so this check nullifies the second
    // function call
    if (evt.target.nodeName == 'svg')
        return;
    // This is necessary with firefox native renderer, otherwise the
    // "mousedown" event would cause the attempt of dragging svg
    // elements
    evt.preventDefault();

    var p = mouseCoordsSvg(evt);

    if(g['shape'].active)
        // When the current tool corresponds to a shape, mouse events are
        // forwarded to the shape
        g['shape'].mousedown(p.x, p.y);
    else{
        // Other tools not binded with shapes, but that act on a
        // clicked shape. First ignore the operation if the target is
        // 'canvas', then retrieve the shape parent group and execute
        // the action
        var target = evt.target;
        if (target.id == 'canvas'){
            mylog('Ignoring attempt to act on the canvas');
            if (g['tool']=='delete')
                // Activate the "dragging delete"
                g['active_tool'] = true;
        }
        else{
            // To search the group and then to come back to the childs
            // can look weird, but this is useful because changing the
            // renderer, the element which raises the event (for
            // example in a text structure) may change
            var group = find_group(target.parentNode);
            switch(g['tool']){
            case 'select':
                var shape = group.childNodes[0];
                // Select is useful only for the links, which have
                // 'text' as top node. They can distinguished by plain
                // text elements because they have a first <tspan>
                // element with visibility="hidden"
                if (shape.nodeName == 'text' &&
                    shape.childNodes[0].getAttribute('visibility')=='hidden'){
                    var url = shape.childNodes[1].data;
                    // Urls without the http look nicer, but it has to
                    // be added, otherwise they will be taken as
                    // relative links
                    if (!/http/.test(url))
                        url = 'http://'+url;
                    window.open(url);
                }
                break;
            case 'edit':
                var shape = group.childNodes[0];
                // Paths can't be edited
                if (shape.nodeName == 'path')
                    mylog('Ignoring attempt to edit a path');
                else{
                    // Distinguish a 'link' shape
                    if (shape.nodeName == 'text' &&
                        shape.childNodes[0].getAttribute('visibility')=='hidden')
                        var shape_name = 'link';
                    else
                        var shape_name = shape.nodeName;
                    show_additional_panel(shape_name);
                    // Copy the old object
                    g['shape'] = g['shape_generator'].generate_shape(shape_name);
                    g['shape'].copy_shape(shape);
                    g['shape'].mousedown(p.x, p.y);
                    // Delete the old object: sender_add is called
                    // with the asynchronous flag set to true, so the
                    // delete update will be sent together with the
                    // new shape update.
                    var group_id = group.getAttribute('id');
                    sender_add('delete', [], group_id, true);
                    g['pages'].remove(group);
                }
                break;
            case 'delete':
                delete_object(group);
                g['active_tool'] = true;
                break;
            case 'move':
                g['moving_object'] = group;
                var transMatrix = g['moving_object'].getCTM();
                // g['p'] will be read on mouse up, and to correctly
                // compute the new transformation we must consider the
                // original position of the object, not the one seen
                // by the user and the pointer
                p.x = p.x - Number(transMatrix.e);
                p.y = p.y - Number(transMatrix.f);
                g['active_tool'] = true;
                break;
            }
        }
    }
    // Store the initial mouse position for the other event handlers
    g['p'] = p;
}

/*
 * MouseMove -- update the geometry of the current object. If there
 * is an open shape, it handles mouse events. Otherwise, maybe the
 * user is trying to move an object or to delete objects dragging the
 * mouse with the 'delete' tool activated
 */
function handleMouseMove(evt) {
    evt.preventDefault();
    var p = mouseCoordsSvg(evt);
    if(g['shape'].active){
        g['shape'].mousemove(p.x, p.y);
    }
    else if (g['active_tool']){
        if (g['tool']=='move'){
            var t = [];
            t.x = p.x - g['p'].x;
            t.y = p.y - g['p'].y;
            g['moving_object'].setAttribute('transform',
                                            'translate('+t.x+','+t.y+')');
        }
        else if (g['tool']=='delete'){
            var target = evt.target;
            if (target.NodeName !== 'svg' && target.id !== 'canvas'){
                var group = find_group(target.parentNode);
                delete_object(group);
            }
        }
    }
}

/*
 * MouseUp -- send finished drawing to the server
 */
function handleMouseUp(evt)
{
    mylog('Started handleMouseUp with target nodeName '+evt.target.nodeName);
    
    if (g['shape'].active){
        g['shape'].mouseup();
    }
    else if (g['active_tool']){
        if (g['tool']=='move'){
            var p = mouseCoordsSvg(evt);
            var t = [];
            t.x = p.x - g['p'].x;
            t.y = p.y - g['p'].y;
            parameters = [t.x, t.y];
            objId = g['moving_object'].getAttribute('id');
            sender_add('move', parameters, objId);
            g['moving_object'] = null;
        }
        g['active_tool'] = false;
    }
}

/* Handler of stroke color buttons */
function handleStroke(event) {
    mylog('handleStroke');
    var target = cross_target(event);
    var new_class = target.className;
    getById('stroke_sample').className = new_class;
    // Extract the color from the class name, that is like:
    // "class_sample class_000000"
    var new_color = '#' + new_class.split(' ')[1].split('_')[1];
    g['shape'].stroke = new_color;
    g['shape_generator'].default_values.stroke = new_color;
}

/* Handler of fill color buttons */
function handleFill(event) {
    var target = cross_target(event);
    var new_class = target.className;
    getById('fill_sample').className = new_class;
    // Extract the color from the class name, that is like:
    // "class_sample class_000000"
    var new_color = '#' + new_class.split(' ')[1].split('_')[1];
    g['shape'].fill = new_color;
    g['shape_generator'].default_values.fill = new_color;
}

function whiteboardOnKeyUp(e) {
    // The corresponding event is raised only by the visible text area,
    // and the text area is visible only when a shape is present
    g['shape'].onkeyup(e);
}

/* Validation on shape attributes. It is used for additional panels */
function set_shape_attribute(name, event, regexp){
    var target = cross_target(event);
    var valid = validate(target, regexp);
    if (valid){
        g['shape'][name] = target.value;
        g['shape_generator'].default_values[name] = target.value;
        // The two inputs 'open_stroke' and 'closed_stroke' must hold
        // the same value, because they both refer to the same
        // variable, the stroke width
        if (name === 'stroke-width')
            getInput('open_stroke').value = getInput('closed_stroke').value = target.value;
    }
}

function delete_object(group){
    // Delete the selected object
    g['pages'].remove(group);
    // Add the delete operation to the update buffer
    sender_add('delete', [], group.getAttribute('id'));
}

function whiteboardServerUpdate(objId, page, typ, par){
    var shape = g['shape_generator'].generate_shape(typ, page);
    // If this is an action involving a shape
    if(shape.active)
        shape.server_create(par, objId);
    else
        switch(typ){
        case 'move':
            var group = getById(objId);
            if (group) {
                var transform = 'translate('+par[0]+','+par[1]+')';
                group.setAttribute('transform', transform);
            }
            // This can happen if someone deletes a object while someone
            // else is moving it
            else
                mylog('Cannot move nonexistent object ' + objId);
            break;

        case 'delete':
            var group = getById(objId);
            // make sure the object has not been already deleted (there is no
            // server side check, and the cuncurrency allows two delete
            // operations on the same object)
            if (group)
                g['pages'].remove(group, page);
            break;

        case 'clear':
            g['pages'].clear(page);
            break;
        }
}
// $Id: chat.js 132 2010-12-13 10:53:17Z s242720-studenti $

function chatServerUpdate(madeBy, objId, par, time){
    var date = new Date(time*1000);
    var text = unescape(par[0]);
    showMessage(objId, madeBy, date, text, true);
}

function chatOnKeyUp(e) {
    key = cross_which(e);
    // This function should continue only if the user pressed just
    // "enter" (and not "shift+enter")
    if (key == 13 && !cross_shift(e)){
        var ti = getById('text_input_t');
        // Trim the trailing newline
        var text = ti.value.slice(0, -1);
        // If the text is not empty send an update to the server and
        // show the message
        if (text!='') {
            var objId = g['idObj'].get_new();
            // Escape the text for separators like :, |, etcetera. It
            // must be unescaped client side by the chatServerUpdate
            // function, and server side where needed. The escape is
            // done two times, because it is unescaped once by the
            // server while reading the POST ajax request, whose mime
            // type is "application/www-form-urlencoded"
            var escaped_text = escape(escape(text));
            var parameters = [escaped_text];
            var pagecp = g['page'];
            g['page'] = -1;
            sender_add('chat', parameters, objId);        
            g['page'] = pagecp;
            showMessage(objId, S['user'], '', text, false);
        }
        ti.value = '';
    }
    return false;
}

/*
 * If called by server, show or confirm a message; if called by the
 * user, show an unconfirmed message and send the update to the server
 */
function showMessage(objId, sender, d, text, server){
    // This is changed if the server is going to confirm an existing message
    var new_message = true;
    // If showMessage is called by a server update set the proper
    // style, and add the date
    if(server){
        var skin = g['skin_names'][g['current_skin_id']];
        // For example: Wed Dec 09 14:44
        var date_string = (parseInt(d.getUTCMonth())+1)+' '+d.getUTCDate()+' '+
            d.getUTCHours()+':'+d.getUTCMinutes();
        // If the sender is this user
        if(sender==S['user']){
            var div_from_class = 'div_from_me_s_'+skin;
            var div_message = getById(objId);
            // If the message already exists, retrieve its elements for
            // confirmation (that means to change their classes), and add
            // the date
            if(div_message){
                var div_from = div_message.childNodes[0];
                var div_date = div_message.childNodes[1];
                div_date.appendChild(document.createTextNode(date_string));
                var div_text = div_message.childNodes[2];
                var hr = div_message.childNodes[3];
                new_message = false;
            }
        }
        // If the sender is not this user
        else
            var div_from_class = 'div_from_s_'+skin;
    }
    // If showMessage is not called by the server (it's called by
    // onkeyup event raised by the user) set the 'unconfirmed' style
    else{
        var skin = 'unconfirmed';
        var div_from_class = 'div_from_me_s_unconfirmed';
    }
    // Both server and user's calls may need to build a new message div
    if(new_message){
        // Create main divs
        var div_from = document.createElement('div');
        var div_date = document.createElement('div');
        var div_text = document.createElement('div');
        var hr = document.createElement('hr');
        var div_message = document.createElement('div');
        div_message.setAttribute('id', objId);

        // Append the divs into the message div, append the message div into
        // the text output div
        div_from.appendChild(document.createTextNode(sender));
        // 'unconfirmed' user messages don't have a date (in that case,
        // 'time' is undefined)
        if(date_string)
            div_date.appendChild(document.createTextNode(date_string));
        textRender(unescape(text), div_text);
        div_message.appendChild(div_from);
        div_message.appendChild(div_date);
        div_message.appendChild(div_text);
        div_message.appendChild(hr);
        getById('text_output').appendChild(div_message);
    }
    // Both new and confirmed messages need to change their class names
    div_message.className = 'div_message_s_'+skin;
    div_from.className = div_from_class;
    div_date.className = 'div_date_s_'+skin;
    div_text.className = 'div_text_s_'+skin;
    hr.className = 'hr_s_'+skin;
    // Adjust scroll (always go to the bottom)
    getById('text_output').scrollTop = getById('text_output').scrollHeight;
}

/*
  Takes a text and puts it into p:
  - substituting the emoticons with corresponding images
  - putting anchors around http:// links
  - replacing newlines with "br"
 */
function textRender(utext, p)
{        
    var utextArray = utext.split('\n');
    for (var i = 0; i < utextArray.length; i++) {
        var v = utextArray[i].split(" ");
        for (var j = 0; j < v.length; j++) {
            var src = "";
            switch(v[j]){                        
                case ":)":                                        
                    src = "smile.gif";        
                    break;                                
                case ":(":
                    src = "sad.gif";
                    break;
                case ":P":
                    src = "tongue.gif";
                    break;
                case ":O":
                    src = "wonder.gif";
                    break;                        
                case ":D":
                    src = "laugh.gif";
                    break;
                default:                                        
                    break;
            }
            // emoticon generation
            if (src != "") {
                var img = document.createElement("img");
                img.setAttribute("src", "images/emot_" + src);
                img.setAttribute("alt", v[j]);
                img.setAttribute("width", "16px");
                img.setAttribute("height", "16px");                
                p.appendChild(img);                        
                p.appendChild(document.createTextNode(" "));
            }
            else {
                if (!v[j].match("http://"))
                    p.appendChild(document.createTextNode(v[j]));
                else // anchor generation
                    {
                        var a = document.createElement("a");
                        a.setAttribute("href", v[j]);
                        a.setAttribute("target", "_blank");
                        a.appendChild(document.createTextNode(v[j]));
                        p.appendChild(a);
                    }
                p.appendChild(document.createTextNode(" "));        
            }                        
        }
        if (i < utextArray.length - 1)
            p.appendChild(document.createElement("br"));
    }
}

// remove all elements from the chat window
function clearChat() {
    var div = getById("text_output");
    while (div.hasChildNodes())
        div.removeChild(div.firstChild);
}
// $Id: menu.js 113 2010-11-10 12:18:41Z s242720-studenti $

function initMenu() {
    // This (skin image creation) could be moved to server side
    var p = getById('style_div');
    for (var i = 0; i < g['skin_names'].length; i++) {
        var skin = g['skin_names'][i];
        var img = document.createElement('img');
        var attributes = {'id' : 'img_'+i,
                          'src': 'images/skin_'+skin+'.gif',
                          'alt': skin};
        sa(img, attributes);
        // The default selected skin
        if (i==0)
            img.className = 'img_selected_s_default';
        else
            img.className = 'skin';
        // This attribute must be set differently with explorer
        if (img.attachEvent)
            img.attachEvent('onclick', changeSkin);
        else
            img.addEventListener('click', changeSkin, false);
        p.appendChild(document.createTextNode(' '));
        p.appendChild(img);                        
    }
}

/* 'logout' and 'del_ses' are directly binded to menu buttons */
function logout(msg){
    // This message will be shown on the new login page
    if (!msg)
        var msg = 'User logged out successfully';
    var query = {'mode':'logout', 'client_id':S['client_id'], 'message':msg};
    send_post('index.php', query);
}
function del_ses(){
    if(confirm('Do you really want to delete the session? (All whiteboard pages and chat messages will be deleted)')){
        var query = {'mode':'delete', 'client_id':S['client_id']};
        send_post('index.php', query);
    }
}

/*
 * Show a form (for complex operations) into the control panel
 */
function show_div(name, show){
    if(show){
        getById('control_default').style.display = 'none';
        getById(name).style.display = '';
    }
    else{
        getById(name).style.display = 'none';
        getById('control_default').style.display = '';
    }
    // The whiteboard could be recentered after the appearence of a
    // wider menu div, so the offsets of the SVG canvas must be
    // updated
    update_canvas_offsets();
}

/*
 * Send the input form, adding the missing values as hidden inputs
 */
function import_submit(){
    var import_form = getById('import_form');
    var inputs = {'user': S['user'],
                  'client_id': S['client_id'],
                  'signature': g['signer'].get_signature()};
    for(key in inputs){
        var input = document.createElement('input');
        sa(input,{'type':'hidden','name':key,'value':inputs[key]});
        import_form.appendChild(input);
    }
    // global variable to set when changing the page, see the
    // description on the top of common.js file
    g['abort_all_ajax_requests'] = true;
    // To upload in an asynchronous way (see
    // http://www.openjs.com/articles/ajax/ajax_file_upload/)
    import_form.target = 'import_target_frame';
    import_form.submit();
    show_div('menu_import', false);
}

/*
 * Adapt the appearence of the export div depending on choosen options
 */
function export_mode_change(event){
    var select = cross_target(event);
    var value = select.value;
    // To simplify the code we should keep the previous value
    switch(value){
    case 'interval':
        getById('export_extension_choice').className = 'hidden';
        getById('export_pages').className = 'inline';
        break;
    case 'current':
        getById('export_pages').className = 'hidden';
        getById('export_extension_choice').className = 'inline';
        break;
    case 'all':
        getById('export_pages').className = 'hidden';
        getById('export_extension_choice').className = 'hidden';
        break;
    case 'chat':
        getById('export_pages').className = 'hidden';
        getById('export_extension_choice').className = 'hidden';
        break;
    }
}

/*
 * The export form doesn't have a 'submit' button, its contents are
 * processed by this javascript function that sends a GET request and
 * opens a new page for the result (but the php code tells to the
 * browsers to save the file that will be showed)
 */
function export_submit(){
    var export_form = getById('export_form');
    var query = 'mode=export';

    // Page intervals can be exported only in pdf format. Here the
    // selected extension rules on the selected mode
    if(export_form['export_extension'].value != 'pdf')
        export_form['exp_mode'].value = 'current';
    switch(export_form['exp_mode'].value){
    case 'current':
        // If the choosen mode is 'current', send the value of the
        // current page instead of the value of the starting page
        getInput('exp_from').value = g['pages'].current;
        break;
    case 'all':
    case 'interval':
        // Page intervals can be exported only as pdf files
        getInput('export_extension').value = 'pdf';
        break;
    }

    // Values to be retrieved from form inputs (we don't care if some of
    // them isn't needed with some export mode)
    var inputs = ['exp_mode', 'exp_from', 'exp_to', 'export_extension'];
    for(i in inputs)
        query += '&'+inputs[i]+'='+export_form[inputs[i]].value;
    query += '&client_id='+S['client_id'];
    // Add the signature
    query += '&signature='+g['signer'].get_signature();
    window.open('index.php?'+query);
    show_div('menu_export', false);
}

/*
 * Append an iframe to the slides div and resize the chat to show it
 */
function load_slides(url){
    // If url is not defined, the function is called by the
    // user. Otherwise, it is called during the whiteboard
    // initialization
    if (!url){
        var url = getInput('slides_address').value;
        var user = true;
    }
    else
        var user = false;
    // Try to add http:// if it is missing
    if (!url.match(/http:\/\//))
        url = 'http://'+url;
    // Remove the old iframe if it exists
    if(getById('slides_frame'))
        getById('right_bottom').removeChild(getById('slides_frame'));
    var new_iframe = document.createElement('iframe');
    sa(new_iframe, {'id':'slides_frame', 'src':url, 'width':'100%',
                'height':'100%', 'onload':'this.contentWindow.focus()'});
    // Listen onload event (special case for Explorer)
    if(sniff() == 'explorer'){
        var load_func =
            function(){getById('slides_frame').contentWindow.focus();};
        new_iframe.attachEvent('onload', load_func);
    }
    getById('right_bottom').appendChild(new_iframe);
    // Toggle slides visibility
    right_resize('mid');
    // Escape the text for separators like :, /, etcetera. It must be
    // unescaped client side and server side where needed. The escape is
    // done two times, because it is unescaped once by the server while
    // reading the POST ajax request, whose mime type is
    // "application/www-form-urlencoded"
    if (user)
        sender_add('slides', [escape(escape(url))]);
    show_div('menu_slides', false);
}

/*
 * Remove the iframe and resize the chat to hide the slides div
 */
function hide_slides(){
    // Remove the old iframe if it exists
    if(getById('slides_frame'))
        getById('right_bottom').removeChild(getById('slides_frame'));
    // Toggle slides visibility
    right_resize('max');
    sender_add('slides', ['']);
    show_div('menu_slides', false);
}

/*
 * Ask to the receiver object to refresh all database lines
 */
function refresh(){
    // To perform a refresh, the following set instruction is the only
    // necessary one. The following function call is to restart the
    // receive cycle.
    g['receiver'].refresh = true;
    // This call allows to restart update receiving, in case an error
    // had stopped their cycle. If that cycle is up, the new call will
    // be ignored.
    receiver_send();
}

// Skin values for style changing
g['skin_names'] = ['default', 'facebook', 'msn'];
g['current_skin_id'] = 0;

function changeSkin(event) {
    target = cross_target(event);
    skin_id = parseInt(target.id.slice(-1));
    if (skin_id == g['current_skin_id'])
        return;
    else{
        // Change selected skin image
        var old_img = getById('img_'+g['current_skin_id']);
        old_img.className='skin';
        var img = getById('img_'+skin_id);
        img.className = 'skin img_selected_s_'+g['skin_names'][skin_id];
        // Change container class
        getById('container_div').className =
            'container_div_s_'+g['skin_names'][skin_id];
        // Change the class of chat messages
        recursiveChange(getById('text_output'),
                        g['skin_names'][g['current_skin_id']],
                        g['skin_names'][skin_id]);
        // Update current skin
        g['current_skin_id'] = skin_id;
    }
}

function recursiveChange(elem, old_skin, new_skin){
    if(elem.className && elem.className.match(old_skin))
        elem.className = elem.className.replace(old_skin, new_skin);
    if(elem.childNodes[0])
        for(var i=0; i<elem.childNodes.length; i++)
            recursiveChange(elem.childNodes[i], old_skin, new_skin);
}
