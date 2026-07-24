(function( $ ) {
    let query_count = 0;

	function attr_text(value) {
		return String(value || '').replace(/[&<>"']/g, function(char) {
			return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char];
		});
	}

	function image_html(item) {
		if (item.image == '') return '';

		var onerror = '';
		var alt = attr_text(item.name);
		if (item.type == 'item') {
			var id = parseInt(item.id);
			onerror = ' onerror="this.onerror=function(){this.removeAttribute(\'onerror\'); this.src=\'/img/icons/' + id + '_64.png\';}; this.src=\'https://images.evetech.net/types/' + id + '/bp?size=32\';"';
		}

		if (item.type == 'ship') {
			var pipLabel = item.pip ? item.pip.replace(/^pip_/, '').replace(/\.png$/, '').replace(/^tech([0-9])$/, 'Tech $1') : '';
			pipLabel = pipLabel ? pipLabel.charAt(0).toUpperCase() + pipLabel.slice(1) : '';
			var pip = item.pip ? '<img class="pip" src="/img/pips/' + item.pip + '" alt="' + attr_text(pipLabel) + '">' : '';
			return '<span class="shipImageSpan" data-l="32px" data-i="32" style="height: 32px; width: 32px; --size: 32px; --sizei: 32;">' +
				'<img class="shipImageRender eveimage img-rounded" src="' + item.image + '" width="32" height="32" alt="' + alt + '" onerror="this.setAttribute(\'shipImageError\', \'true\')">' +
				pip +
				'</span>';
		}

		return '<img src="' + item.image + '" width="32" height="32" alt="' + alt + '"' + onerror + '>';
	}

	var zz_search = function(element, callback) {
		//create our objects and things
		this.data = {}, this.data['element'] = element, this.data['menu'] = $('<ul class="autocomplete dropdown-menu" style="display: none;"></ul>').appendTo('body'), this.callback = callback;
		this.data['isNavbarSearch'] = element.attr('id') == 'searchbox';
		if (this.data['isNavbarSearch']) this.data['menu'].addClass('nav-search-autocomplete');
		this.data['source'] = element.data('zkbAutocompleteSource') || '/autocomplete/';
		this.data['linkPrefix'] = element.data('zkbAutocompleteLinkPrefix') || '';
		this.data['submitFormOnEnter'] = element.data('zkbAutocompleteSubmitForm') === true || element.attr('data-zkb-autocomplete-submit-form') != null;
		this.data['placeholder'] = element.attr('placeholder') || '';
		if (this.data['source'].slice(-1) != '/') this.data['source'] += '/';

		//bind our primary search event
		this.data['element'].on('keyup', $.proxy(function(event) { if (!event.isDefaultPrevented() && event.keyCode != 9 && event.keyCode != 38 && event.keyCode != 40) { return $.proxy(this.do_search(event), this); } }, this));
		
		//bind our utility keypress stuff events
		this.data['element'].on('keydown', $.proxy(function(event) { 
			event.stopPropagation();
			switch(event.keyCode) {
				case 38: $.proxy(this.move_prev(event), this); break;
				case 40: $.proxy(this.move_next(event), this); break;
				case 27: this.data['element'].val(''); this.hide_menu(event); this.data['element'].blur(); break;
			}
		}, this));
		
		//handle any enter key presses intelligently
		this.data['element'].on('keypress', $.proxy(function(event) {
			event.stopPropagation();
			if (event.keyCode != 13) return;
			if (this.data['menu'].find('.active').length == 1) {
				event.preventDefault();
				this.run_callback(event);
			} else if (!this.data['submitFormOnEnter']) {
				event.preventDefault();
			}
		}, this));
		
		//handle a couple of other types of event
		this.data['element'].on('focus', $.proxy(function(){
			if (this.data['isNavbarSearch']) {
				this.data['element'].closest('.nav-search-item').addClass('search-active');
				this.data['element'].attr('placeholder', 'ESC to clear search');
			}
		}, this));
		this.data['element'].on('blur', $.proxy(function(){
			this.hide_menu();
			if (this.data['isNavbarSearch']) {
				this.data['element'].attr('placeholder', this.data['placeholder']);
				setTimeout($.proxy(function() {
					if (!this.data['element'].is(':focus')) this.data['element'].closest('.nav-search-item').removeClass('search-active');
				}, this), 150);
			}
		}, this));
		this.data['menu'].on('click', 'a', $.proxy(function(event){ this.run_callback(event); }, this));	
		this.data['menu'].on('mouseenter', 'li', $.proxy(function(event){ if ($(event.currentTarget).data('value') == null) return; this.data['menu'].find('.active').removeClass('active'); $(event.currentTarget).addClass('active').addClass('active'); }, this));
	}

	//add all the functions we need
	zz_search.prototype = {
		
		constructor: zz_search,
		
		//get the position of the input so we can correctly offset the search window
		get_position: function() {
			var pos = $.extend({}, this.data['element'].offset(), { height: this.data['element'][0].offsetHeight });
			var inputGroup = this.data['element'].closest('.input-group');
			var anchor = inputGroup.length ? inputGroup : this.data['element'];
			var anchorOffset = anchor.offset();
			var isMobile = window.matchMedia('only screen and (max-width: 767px)').matches;
			var alignInput = this.data['element'].data('zkbAutocompleteAlign') == 'input';
			var menuWidth = (isMobile || alignInput) ? anchor.outerWidth() : 375;
			var searchOffsetX = (isMobile || alignInput) ? anchorOffset.left : pos.left - 90;
			var searchOffsetY = pos.top + pos.height;
			var viewportLeft = $(window).scrollLeft();
			var viewportWidth = $(window).width();
			var gutter = 8;

			if (isMobile && this.data['isNavbarSearch']) {
				menuWidth = viewportWidth - (gutter * 2);
				searchOffsetX = viewportLeft + gutter;
			} else {
				menuWidth = Math.max(200, Math.min(menuWidth, viewportWidth - (gutter * 2)));
				if (searchOffsetX + menuWidth > viewportLeft + viewportWidth - gutter) searchOffsetX = viewportLeft + viewportWidth - gutter - menuWidth;
				if (searchOffsetX < viewportLeft + gutter) searchOffsetX = viewportLeft + gutter;
			}

			return { top: searchOffsetY, left: searchOffsetX, width: menuWidth };
		},
				
		//move the selection around
		move_prev: function(event) { event.preventDefault(); var active = this.data['menu'].find('li.active[data-value]'); var prev = active.prevAll('li[data-value]').first(); active.removeClass('active'); if (prev.length == 0) prev = this.data['menu'].find('li[data-value]').last(); prev.addClass('active'); },
		move_next: function(event) { event.preventDefault(); var active = this.data['menu'].find('li.active[data-value]'); var next = active.nextAll('li[data-value]').first(); active.removeClass('active'); if (next.length == 0) next = this.data['menu'].find('li[data-value]').first(); next.addClass('active'); },
	
		//goto the selected items seach page
		run_callback: function(event) {
			if (event) {
				event.preventDefault();
				event.stopPropagation();
			}
			var selected = event ? $(event.currentTarget).closest('li').data('value') : null;
			if (selected == null) selected = this.data['menu'].find('.active').data('value');
			this.data['element'].val('');
			this.hide_menu(event);
			this.data['element'].blur();
			var result = this.callback(selected, event);
			if (this.data['isNavbarSearch'] && result && typeof result.then == 'function') {
				result.then(function() {
					var content = document.getElementById('zkb-page-content');
					if (content) content.focus({ preventScroll: true });
				});
			}
		},
	
		//hide the drop down
		hide_menu: function(event) { this.data['menu'].fadeOut(200); },
		
		//the main event - perfom an lookup to see what there is to see
		do_search: function(event) { 
			//clear any throttled searched now now
			clearTimeout(this.data['throttle']);

			//if the saerch string is empty then dont' bother searching
			if ( this.data['element'].val() == '' ) { this.hide_menu(event); return; }

            let current_query_count = ++query_count;
			//create our throttled search
			this.data['throttle'] = setTimeout($.proxy(function() {
                const search = this.data['element'].val();
                if (search.includes('/') || search.includes(':')) return this.data['menu'].empty();
				$.ajax(this.data['source'] + encodeURIComponent(search) + '/', {'type' : 'get', 'dataType' : 'json', 'success' : $.proxy(function(result) {

                    if (current_query_count != query_count) return console.log('search aborted after additional input received');
					//empty the dropdown and append the new data
					this.data['menu'].empty();
					if (result.length == 0) {
						this.data['menu'].append($('<li class="autocomplete-empty"><i class="fas fa-search" aria-hidden="true"></i><span>No results</span></li>'));
					} else {
						var itemHtml = $.proxy(function(item, index) {
							var href = this.data['linkPrefix'] != '' ? this.data['linkPrefix'] + item.id + '/' : '/' + item.type + '/' + item.id + '/';
							return $('<li><a href="' + href + '">' + image_html(item) + '<p style="width: 100%; text-overflow: ellipsis; white-space: nowrap; overflow: hidden;">' + item.name.replace(RegExp('(' + this.data['element'].val() + ')', "gi"), function($1, match){ return '<strong>' + match + '</strong>'; } ) + '</p><span><small>' + item.type + '</small></span></a></li>').attr('data-value', JSON.stringify(item));
						}, this);
						if (this.data['menu'].hasClass('nav-search-autocomplete')) {
							var groups = {};
							var typeOrder = [];
							var typeLabels = { ship: 'Ships', item: 'Items', alliance: 'Alliances', corporation: 'Corporations', character: 'Characters', faction: 'Factions', system: 'Systems', region: 'Regions', constellation: 'Constellations', group: 'Groups', location: 'Locations' };
							$.each(result, $.proxy(function(index, item) {
								if (groups[item.type] == null) {
									groups[item.type] = [];
									typeOrder.push(item.type);
								}
								groups[item.type].push({ item: item, index: index });
							}, this));
							$.each(typeOrder, $.proxy(function(index, type) {
								this.data['menu'].append($('<li class="dropdown-header autocomplete-group-header"></li>').text(typeLabels[type] || type));
								$.each(groups[type], $.proxy(function(index, row) {
									this.data['menu'].append(itemHtml(row.item, row.index));
								}, this));
							}, this));
						} else {
							this.data['menu'].append($.map(result, itemHtml));
						}
					}

					//if its not visible already fade it in - and position it as needed and autoselect the first item
					if (this.data['menu'].hasClass('nav-search-autocomplete')) {
						this.data['menu'].css($.extend(this.get_position(), { display: 'grid' }));
					} else {
						this.data['menu'].css(this.get_position()).not(':visible').fadeIn(200);
					}
					if (result.length > 0) this.data['menu'].find('li[data-value]').first().addClass('active');
				}, this)});
			}, this), 50);
		}
	};

	//define the zz_search method
	$.fn.zz_search = function(callback) {
		return this.each(function() {
			var $this = $(this), data = $this.data('zz_search');
			if (!data) { $this.data('zz_search', (data = new zz_search($this, callback))); }
		});
	}
})( jQuery );
