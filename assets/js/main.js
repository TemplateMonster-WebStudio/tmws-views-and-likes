"use strict";

(function($) {

	const
		tmws_options = window.tmws_options||{}
	
	let
		xhr

	if (! 'endpoint' in tmws_options) {
		return;
	}

	$(document).off('click.tmws_val')
	$(document).on('click.tmws_val', '[data-type="' + (tmws_options.actions?.like||'like') + '"]', handle_like_click)
	$(document).on('click.tmws_val', '[data-type="' + (tmws_options.actions?.dislike||'dislike') + '"]', handle_dislike_click)
	$(document).ajaxError(handle_error)

	function handle_like_click(e) {

		if(!!xhr) {
			xhr.abort()
		}

		const 
			data = {
				action: tmws_options.actions?.like||'like',
				post_id: $(this).data('post_id'),
			}

		xhr = $.post(
			window.location.href + tmws_options.endpoint,
			data,
			handle_hxr_success.bind(this)
		)

		$(this).addClass('loading')
	}

	function handle_dislike_click(e) {

		if(!!xhr) {
			xhr.abort()
		}

		const 
			data = {
				action: tmws_options.actions?.dislike||'dislike',
				post_id: $(this).data('post_id'),
			}

		xhr = $.post(
			window.location.href + tmws_options.endpoint,
			data,
			handle_hxr_success.bind(this)
		)

		$(this).addClass('loading')
	}

	function handle_hxr_success(data, textStatus, jqXHR) {

		const
			{
				result:result,
				data:{
					html:_html_
				}
			} = data,
			$this = $(this),
			post_id = $this.data('post_id')

		$this.removeClass('loading')

		if(!_html_) {
			return
		}

		for(let value in _html_){
			$(`[data-type="${value}"][data-post_id="${post_id}"]`).closest('.wrapper').replaceWith(_html_[value]);
		}
	}

	function handle_error(event, jqXHR, ajaxSettings, thrownError) {
		console.error(thrownError);
	}
})(jQuery)
