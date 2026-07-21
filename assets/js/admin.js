/**
 * Themify admin JS. Loaded only on Themify screens.
 * Handles: color pickers, media picker, copy buttons, repeater add/remove,
 * and a generic AJAX "scan" runner used by the report screens
 * (indexing, rank tracker, SEO health).
 */
( function ( $ ) {
	'use strict';

	$( function () {
		// Color pickers.
		if ( $.fn.wpColorPicker ) {
			$( '.tf-color-picker' ).wpColorPicker();
		}

		// Media picker (logo, badges, images).
		$( document ).on( 'click', '.tf-media__pick', function ( e ) {
			e.preventDefault();
			var wrap = $( this ).closest( '.tf-media' );
			var input = wrap.find( '.tf-media__url' );
			var preview = wrap.find( '.tf-media__preview' );
			var clearBtn = wrap.find( '.tf-media__clear' );
			var frame = wp.media( { title: 'Select image', library: { type: 'image' }, multiple: false } );
			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				input.val( att.url ).trigger( 'change' );
				preview.attr( 'src', att.url ).show();
				clearBtn.show();
			} );
			frame.open();
		} );
		$( document ).on( 'click', '.tf-media__clear', function ( e ) {
			e.preventDefault();
			var wrap = $( this ).closest( '.tf-media' );
			wrap.find( '.tf-media__url' ).val( '' ).trigger( 'change' );
			wrap.find( '.tf-media__preview' ).attr( 'src', '' ).hide();
			$( this ).hide();
		} );

		// Banner gallery: multi-select media picker that appends a thumbnail +
		// hidden input per chosen image (used by the homepage hero slider).
		$( document ).on( 'click', '.tf-gallery__add', function ( e ) {
			e.preventDefault();
			var wrap = $( this ).closest( '.tf-gallery' );
			var name = wrap.data( 'name' );
			var frame = wp.media( { title: 'Select banner images', multiple: 'add' } );
			frame.on( 'select', function () {
				frame.state().get( 'selection' ).each( function ( att ) {
					var a = att.toJSON();
					var url = ( a.sizes && a.sizes.large ) ? a.sizes.large.url : a.url;
					var full = a.url;
					var item = $( '<div class="tf-gallery__item"></div>' );
					$( '<img>' ).attr( 'src', url ).attr( 'alt', '' ).appendTo( item );
					$( '<input type="hidden">' ).attr( 'name', name + '[]' ).val( full ).appendTo( item );
					$( '<button type="button" class="tf-gallery__remove" aria-label="Remove image">&times;</button>' ).appendTo( item );
					wrap.find( '.tf-gallery__items' ).append( item );
				} );
			} );
			frame.open();
		} );
		$( document ).on( 'click', '.tf-gallery__remove', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.tf-gallery__item' ).remove();
		} );

		// Copy-to-clipboard buttons (e.g. IndexNow key, verification tags).
		$( document ).on( 'click', '[data-tf-copy]', function ( e ) {
			e.preventDefault();
			var text = $( this ).attr( 'data-tf-copy' );
			navigator.clipboard && navigator.clipboard.writeText( text );
			var original = $( this ).text();
			$( this ).text( 'Copied!' );
			var el = $( this );
			setTimeout( function () { el.text( original ); }, 1400 );
		} );

		// Repeater rows.
		$( document ).on( 'click', '.tf-repeater__add', function ( e ) {
			e.preventDefault();
			var wrap = $( this ).closest( '.tf-repeater' );
			var tpl = wrap.find( '.tf-repeater__template' ).html();
			var index = wrap.find( '.tf-repeater__row' ).length;
			wrap.find( '.tf-repeater__rows' ).append( tpl.replace( /__INDEX__/g, index ) );
		} );
		$( document ).on( 'click', '.tf-remove', function ( e ) {
			e.preventDefault();
			$( this ).closest( '.tf-repeater__row' ).remove();
		} );

		// Generic AJAX runner: <button class="tf-run" data-action="themify_x">
		$( document ).on( 'click', '.tf-run', function ( e ) {
			e.preventDefault();
			var btn = $( this );
			var action = btn.data( 'action' );
			var target = $( btn.data( 'target' ) || '#tf-run-result' );
			if ( ! action ) { return; }
			var label = btn.html();
			btn.prop( 'disabled', true ).html( '<span class="tf-spinner"></span> ' + ( btn.data( 'running' ) || 'Working…' ) );
			$.post( ( window.themifyAdmin || {} ).ajaxUrl, {
				action: action,
				nonce: ( window.themifyAdmin || {} ).nonce,
				payload: btn.data( 'payload' ) || ''
			} ).done( function ( res ) {
				if ( res && res.data && res.data.html ) {
					target.html( res.data.html );
				} else {
					target.html( '<div class="tf-notice tf-notice--warn">' + ( ( res && res.data && res.data.message ) || 'Done.' ) + '</div>' );
				}
			} ).fail( function ( xhr ) {
				target.html( '<div class="tf-notice tf-notice--warn">Request failed (' + xhr.status + ').</div>' );
			} ).always( function () {
				btn.prop( 'disabled', false ).html( label );
			} );
		} );
	} );
} )( jQuery );
