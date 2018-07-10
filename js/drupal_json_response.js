//alert('hello test js load');

jQuery("[name*=_group_]").click(function(evt){
	
	var childFieldPattern = jQuery(evt.target).attr('name');

	var target = jQuery(evt.target).attr('delete_key');

	var pattern = `[name*=${target}]`;
	//alert(jQuery(pattern).length);
	if(jQuery(evt.target).prop('checked')){
		
		jQuery(pattern).prop('checked',true);
	} else {
		
		jQuery(pattern).prop('checked',false);
	}
});