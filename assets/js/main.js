/**
 * Themify front-end JS. Framework-free, deferred, < 3 KB.
 * Handles: mobile menu, sticky header state, TOC scrollspy + toggle,
 * back-to-top button, and lazy hydration of details/accordions.
 */
( function () {
	'use strict';

	var cfg = window.themifyData || {};

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) { fn(); }
		else { document.addEventListener( 'DOMContentLoaded', fn ); }
	}

	ready( function () {
		mobileMenu();
		stickyHeader();
		backToTop();
		toc();
		sliders();
		shareBar();
		authorTabs();
		copyLinks();
		youtube();
	} );

	/* ---- YouTube section: click a thumbnail to play inline (no iframe until then) ---- */
	function youtube() {
		var grid = document.querySelector( '[data-tf-youtube]' );
		if ( ! grid ) { return; }
		grid.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '.tf-youtube__video' ) : null;
			if ( ! btn || btn.querySelector( 'iframe' ) ) { return; }
			var id = btn.getAttribute( 'data-video' );
			if ( ! id ) { return; }
			var iframe = document.createElement( 'iframe' );
			iframe.setAttribute( 'src', 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent( id ) + '?autoplay=1&rel=0' );
			iframe.setAttribute( 'title', 'YouTube video player' );
			iframe.setAttribute( 'allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture' );
			iframe.setAttribute( 'allowfullscreen', '' );
			btn.innerHTML = '';
			btn.appendChild( iframe );
		} );
	}

	/* ---- Author box tabs (Author / Recent Posts) ---- */
	function authorTabs() {
		var boxes = document.querySelectorAll( '[data-tf-authorbox]' );
		Array.prototype.forEach.call( boxes, function ( box ) {
			var tabs = box.querySelectorAll( '.tf-authorbox__tab' );
			var panels = box.querySelectorAll( '.tf-authorbox__panel' );
			if ( tabs.length < 2 ) { return; }
			// Initial state (progressive enhancement): the markup ships with every
			// panel visible so it stays readable with JS off; here we hide the
			// inactive one now that we can switch between them.
			Array.prototype.forEach.call( panels, function ( p ) {
				if ( ! p.classList.contains( 'is-active' ) ) { p.setAttribute( 'hidden', '' ); }
			} );
			Array.prototype.forEach.call( tabs, function ( tab ) {
				tab.addEventListener( 'click', function () {
					var name = tab.getAttribute( 'data-tab' );
					Array.prototype.forEach.call( tabs, function ( t ) {
						var on = t === tab;
						t.classList.toggle( 'is-active', on );
						t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					} );
					Array.prototype.forEach.call( panels, function ( p ) {
						var on = p.getAttribute( 'data-panel' ) === name;
						p.classList.toggle( 'is-active', on );
						if ( on ) { p.removeAttribute( 'hidden' ); } else { p.setAttribute( 'hidden', '' ); }
					} );
				} );
			} );
		} );
	}

	/* ---- Floating share bar: open/close toggle + copy-link ---- */
	function shareBar() {
		var bar = document.querySelector( '[data-tf-share]' );
		if ( ! bar ) { return; }
		var toggle = bar.querySelector( '.tf-share__toggle' );

		if ( toggle ) {
			toggle.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var open = bar.classList.toggle( 'is-open' );
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
			// Close on outside click / Escape.
			document.addEventListener( 'click', function ( e ) {
				if ( bar.classList.contains( 'is-open' ) && ! bar.contains( e.target ) ) {
					bar.classList.remove( 'is-open' );
					toggle.setAttribute( 'aria-expanded', 'false' );
				}
			} );
			document.addEventListener( 'keydown', function ( e ) {
				if ( e.key === 'Escape' && bar.classList.contains( 'is-open' ) ) {
					bar.classList.remove( 'is-open' );
					toggle.setAttribute( 'aria-expanded', 'false' );
				}
			} );
		}

	}

	/* ---- Copy-link buttons (corner share bar + in-article share) ---- */
	function copyLinks() {
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest ? e.target.closest( '[data-tf-copy-link]' ) : null;
			if ( ! btn ) { return; }
			e.preventDefault();
			var url = btn.getAttribute( 'data-tf-copy-link' ) || window.location.href;
			var cls = btn.className.indexOf( 'tf-artshare' ) !== -1 ? 'tf-artshare__btn--copied' : 'tf-share__btn--copied';
			var done = function () {
				btn.setAttribute( 'data-copied', 'Copied!' );
				btn.classList.add( cls );
				window.setTimeout( function () { btn.classList.remove( cls ); }, 1600 );
			};
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( url ).then( done, done );
			} else {
				var ta = document.createElement( 'textarea' );
				ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
				document.body.appendChild( ta ); ta.select();
				try { document.execCommand( 'copy' ); } catch ( err ) {}
				document.body.removeChild( ta ); done();
			}
		} );
	}

	/* ---- Hero banner slider (crossfade, auto-advance, library-free) ---- */
	function sliders() {
		var nodes = document.querySelectorAll( '.tf-slider' );
		Array.prototype.forEach.call( nodes, function ( slider ) {
			var slides = slider.querySelectorAll( '.tf-slide' );
			if ( slides.length < 2 ) { return; }
			var dots = slider.querySelectorAll( '.tf-slider__dot' );
			var i = 0, timer = null;
			var delay = parseInt( slider.getAttribute( 'data-autoplay' ), 10 ) || 6000;
			var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

			function show( n ) {
				slides[ i ].classList.remove( 'is-active' );
				if ( dots[ i ] ) { dots[ i ].classList.remove( 'is-active' ); }
				i = ( n + slides.length ) % slides.length;
				slides[ i ].classList.add( 'is-active' );
				if ( dots[ i ] ) { dots[ i ].classList.add( 'is-active' ); }
			}
			function start() { if ( reduce ) { return; } stop(); timer = window.setInterval( function () { show( i + 1 ); }, delay ); }
			function stop() { if ( timer ) { window.clearInterval( timer ); timer = null; } }

			var next = slider.querySelector( '.tf-slider__nav--next' );
			var prev = slider.querySelector( '.tf-slider__nav--prev' );
			if ( next ) { next.addEventListener( 'click', function () { show( i + 1 ); start(); } ); }
			if ( prev ) { prev.addEventListener( 'click', function () { show( i - 1 ); start(); } ); }
			Array.prototype.forEach.call( dots, function ( dot, idx ) {
				dot.addEventListener( 'click', function () { show( idx ); start(); } );
			} );
			slider.addEventListener( 'mouseenter', stop );
			slider.addEventListener( 'mouseleave', start );
			start();
		} );
	}

	/* ---- Mobile menu ---- */
	function mobileMenu() {
		var toggle = document.querySelector( '.tf-nav-toggle' );
		var menu = document.querySelector( '.tf-menu' );
		if ( ! toggle || ! menu ) { return; }
		toggle.addEventListener( 'click', function () {
			var open = menu.classList.toggle( 'is-open' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			document.body.style.overflow = open ? 'hidden' : '';
		} );
		// Close on outside click / escape.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' && menu.classList.contains( 'is-open' ) ) {
				menu.classList.remove( 'is-open' );
				toggle.setAttribute( 'aria-expanded', 'false' );
				document.body.style.overflow = '';
			}
		} );
	}

	/* ---- Sticky header shadow ---- */
	function stickyHeader() {
		var header = document.querySelector( '.tf-site-header' );
		if ( ! header ) { return; }
		if ( cfg.stickyNav ) { header.classList.add( 'is-sticky' ); }
		var last = 0;
		window.addEventListener( 'scroll', function () {
			var y = window.pageYOffset;
			header.classList.toggle( 'is-scrolled', y > 12 );
			last = y;
		}, { passive: true } );
	}

	/* ---- Back to top ---- */
	function backToTop() {
		var btn = document.querySelector( '.tf-back-to-top' );
		if ( ! btn ) { return; }
		window.addEventListener( 'scroll', function () {
			btn.classList.toggle( 'is-visible', window.pageYOffset > 600 );
		}, { passive: true } );
		btn.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		} );
	}

	/* ---- TOC toggle + scrollspy ---- */
	function toc() {
		var nav = document.querySelector( '.tf-toc' );
		if ( ! nav ) { return; }

		var toggle = nav.querySelector( '.tf-toc__toggle' );
		if ( toggle ) {
			toggle.addEventListener( 'click', function () {
				var expanded = toggle.getAttribute( 'aria-expanded' ) !== 'false';
				toggle.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
				nav.hidden = false;
				var list = nav.querySelector( 'ol' );
				if ( list ) { list.style.display = expanded ? 'none' : ''; }
			} );
		}

		var links = Array.prototype.slice.call( nav.querySelectorAll( 'a[href^="#"]' ) );
		if ( ! links.length || ! ( 'IntersectionObserver' in window ) ) { return; }
		var map = {};
		links.forEach( function ( a ) {
			var id = decodeURIComponent( a.getAttribute( 'href' ).slice( 1 ) );
			var el = document.getElementById( id );
			if ( el ) { map[ id ] = a; }
		} );
		var obs = new IntersectionObserver( function ( entries ) {
			entries.forEach( function ( entry ) {
				if ( entry.isIntersecting ) {
					links.forEach( function ( a ) { a.classList.remove( 'is-active' ); } );
					var active = map[ entry.target.id ];
					if ( active ) { active.classList.add( 'is-active' ); }
				}
			} );
		}, { rootMargin: '-20% 0px -70% 0px' } );
		Object.keys( map ).forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) { obs.observe( el ); }
		} );
	}
} )();
