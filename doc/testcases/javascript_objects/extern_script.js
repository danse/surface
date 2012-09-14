var function_literal = function(){};

var extern_object = {
   state: 0,
   click: function(){
     alert(this.state++)
   }
 };

function try_extern_object(){
  var a_object = {
    init: function(arg){
      this.state = arg;
    },
    change: function(arg){
      this.state += arg;
    },
    show: function(){
      alert(this.state);
      this.change(this.state);
    }
  };
  a_object.init(0);
  a_object.change(2);
  a_object.show();
  a_object.show();
  a_object.init('a');
  a_object.change('b');
  a_object.show();
  a_object.show();
}

