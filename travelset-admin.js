jQuery(function($){
  'use strict';
  if (typeof GKTS_ADMIN === 'undefined') return;

  var productCounter = 10000;
  var ruleCounter = 20000;
  var groupCounter = 30000;
  var variationCache = {};

  $('#gkts-products .gkts-product-config').each(function(){
    var n = parseInt($(this).attr('data-product-index'), 10);
    if (!isNaN(n)) productCounter = Math.max(productCounter, n + 100);
  });

  function initEnhanced($context){
    $context = $context || $(document);
    $context.find('.gkts-category-select').each(function(){
      var $s = $(this);
      if ($s.hasClass('select2-hidden-accessible')) return;
      if ($.fn.selectWoo) $s.selectWoo({width:'100%', allowClear:true});
      else if ($.fn.select2) $s.select2({width:'100%', allowClear:true});
    });
  }

  function initProductSearch(){
    // WooCommerce initialisiert dynamisch eingefügte .wc-product-search-Felder über dieses Event.
    $(document.body).trigger('wc-enhanced-select-init');
  }

  function updateTotal($rule){
    var total = 0;
    $rule.find('.gkts-group-count').each(function(){
      total += Math.max(0, parseInt($(this).val() || 0, 10));
    });
    $rule.find('.gkts-total span').text(total);
    $rule.find('.gkts-total-field').val(total);
  }

  function updateRuleTitle($rule){
    var name = $.trim($rule.find('.gkts-rule-name').val() || '');
    $rule.find('.gkts-rule-title').text(name || 'Neue Regel');
  }

  function updateProductTitle($config){
    var $select = $config.find('.gkts-product-select').first();
    var text = $.trim($select.find('option:selected').text() || '');
    $config.find('.gkts-product-title').text(text || ((GKTS_ADMIN.texts || {}).newProduct || 'Neues Travel-Set Produkt'));
  }

  function updateEmptyStates(){
    var hasProducts = $('#gkts-products .gkts-product-config').length > 0;
    $('.gkts-empty-products').toggle(!hasProducts);
    $('#gkts-products .gkts-product-config').each(function(){
      var $config = $(this);
      $config.find('.gkts-empty').first().toggle($config.find('.gkts-rules > .gkts-rule').length === 0);
    });
  }

  function fillVariationSelect($select, options, selected){
    selected = String(selected || '');
    options = options || [];
    $select.empty().append($('<option>').val('').text((GKTS_ADMIN.texts || {}).chooseVariation || 'Vorhandene Variante auswählen …'));
    options.forEach(function(v){
      var $o = $('<option>').val(String(v.id)).text(v.text);
      if (String(v.id) === selected) $o.prop('selected', true);
      $select.append($o);
    });
  }

  function currentProductId($config){
    return parseInt($config.find('.gkts-product-select').first().val() || 0, 10) || 0;
  }

  function loadVariations(productId, callback){
    if (!productId) {
      callback([]);
      return;
    }
    if (variationCache[productId]) {
      callback(variationCache[productId]);
      return;
    }

    $.post(GKTS_ADMIN.ajax, {
      action: GKTS_ADMIN.variationAction,
      nonce: GKTS_ADMIN.nonce,
      product_id: productId
    }).done(function(resp){
      var options = (resp && resp.success && resp.data && resp.data.variations) ? resp.data.variations : [];
      variationCache[productId] = options;
      callback(options);
    }).fail(function(){
      callback([]);
    });
  }

  function refreshVariations($config){
    var productId = currentProductId($config);
    var saved = [];
    $config.find('.gkts-variation-select').each(function(){ saved.push(String($(this).val() || '')); });

    if (!productId) {
      $config.find('.gkts-variation-select').each(function(){ fillVariationSelect($(this), [], ''); });
      return;
    }

    // Bei Produktwechsel Cache neu abrufen, damit neu erstellte Varianten sofort auftauchen.
    delete variationCache[productId];
    loadVariations(productId, function(options){
      $config.find('.gkts-variation-select').each(function(i){
        fillVariationSelect($(this), options, saved[i] || '');
      });
    });
  }

  function updateSortOrderFields(){
    $('#gkts-products > .gkts-product-config').each(function(pi){
      var $config = $(this);
      $config.children('.gkts-product-body').children('.gkts-product-order').val(pi);
      $config.find('.gkts-rules').first().children('.gkts-rule').each(function(ri){
        var $rule = $(this);
        $rule.children('.gkts-rule-body').children('.gkts-rule-order').val(ri);
        $rule.find('.gkts-group-list').first().children('.gkts-group').each(function(gi){
          $(this).children('.gkts-group-order').val(gi);
        });
      });
    });
  }

  function initSortables($context){
    $context = $context || $(document);

    var $products = $('#gkts-products');
    if ($products.length && !$products.hasClass('ui-sortable')) {
      $products.sortable({
        items: '> .gkts-product-config',
        handle: '.gkts-product-drag',
        placeholder: 'gkts-product-placeholder',
        tolerance: 'pointer',
        update: updateSortOrderFields
      });
    }

    $context.find('.gkts-rules').each(function(){
      var $list = $(this);
      if ($list.hasClass('ui-sortable')) return;
      $list.sortable({
        items: '> .gkts-rule',
        handle: '.gkts-rule-drag',
        placeholder: 'gkts-sort-placeholder',
        tolerance: 'pointer',
        update: function(){ updateSortOrderFields(); updateEmptyStates(); }
      });
    });

    $context.find('.gkts-group-list').each(function(){
      var $list = $(this);
      if ($list.hasClass('ui-sortable')) return;
      $list.sortable({
        items: '> .gkts-group',
        handle: '.gkts-group-drag',
        placeholder: 'gkts-sort-placeholder',
        tolerance: 'pointer',
        update: function(){ updateSortOrderFields(); updateTotal($list.closest('.gkts-rule')); }
      });
    });
  }

  function addProduct(){
    var pi = productCounter++;
    var html = $('#gkts-product-template').html().replace(/__PRODUCT__/g, pi);
    var $config = $(html).appendTo('#gkts-products');
    initProductSearch();
    initEnhanced($config);
    initSortables($config);
    updateSortOrderFields();
    updateEmptyStates();
    return $config;
  }

  function addRule($config, data){
    var pi = $config.attr('data-product-index');
    var ri = ruleCounter++;
    var html = $('#gkts-rule-template').html().replace(/__PRODUCT__/g, pi).replace(/__RULE__/g, ri);
    var $rule = $(html).appendTo($config.find('.gkts-rules').first());

    var productId = currentProductId($config);
    loadVariations(productId, function(options){
      fillVariationSelect($rule.find('.gkts-variation-select'), options, data && data.variation_id ? data.variation_id : '');
    });

    if (data) {
      $rule.find('.gkts-rule-name').val(data.name || '');
      if (data.enabled === false || data.enabled === 0) $rule.find('input[name$="[enabled]"]').prop('checked', false);
      (data.groups || []).forEach(function(group){ addGroup($rule, group); });
    }

    if (!$rule.find('.gkts-group').length) addGroup($rule, {label:'', count:1, category_ids:[]});
    updateRuleTitle($rule);
    updateTotal($rule);
    initSortables($rule);
    updateSortOrderFields();
    updateEmptyStates();
    return $rule;
  }

  function addGroup($rule, data){
    var $config = $rule.closest('.gkts-product-config');
    var pi = $config.attr('data-product-index');
    var ri = $rule.attr('data-rule-index');
    var gi = groupCounter++;
    var html = $('#gkts-group-template').html()
      .replace(/__PRODUCT__/g, pi)
      .replace(/__RULE__/g, ri)
      .replace(/__GROUP__/g, gi);
    var $group = $(html).appendTo($rule.find('.gkts-group-list').first());

    if (data) {
      $group.find('input[name$="[label]"]').val(data.label || '');
      $group.find('.gkts-group-count').val(data.count || 1);
      $group.find('.gkts-category-select').val((data.category_ids || []).map(String));
    }

    initEnhanced($group);
    initSortables($rule);
    updateSortOrderFields();
    updateTotal($rule);
    return $group;
  }

  $('#gkts-add-product').on('click', function(){
    addProduct();
  });

  $('#gkts-products').on('click', '.gkts-collapse', function(){
    var $btn = $(this);
    var $config = $btn.closest('.gkts-product-config');
    var $body = $config.find('.gkts-product-body').first();
    var open = !$body.is(':visible');
    $body.stop(true, true).slideToggle(120);
    $btn.attr('aria-expanded', open ? 'true' : 'false').text(open ? '▴' : '▾');
  });

  $('#gkts-products').on('click', '.gkts-remove-product', function(){
    if (!window.confirm((GKTS_ADMIN.texts || {}).confirmProduct || 'Konfiguration entfernen?')) return;
    $(this).closest('.gkts-product-config').remove();
    updateSortOrderFields();
    updateEmptyStates();
  });

  $('#gkts-products').on('click', '.gkts-add-rule', function(){
    addRule($(this).closest('.gkts-product-config'), {groups:[{label:'', count:1, category_ids:[]}]});
  });

  $('#gkts-products').on('click', '.gkts-add-group', function(){
    addGroup($(this).closest('.gkts-rule'), {label:'', count:1, category_ids:[]});
  });

  $('#gkts-products').on('click', '.gkts-remove-group', function(){
    var $rule = $(this).closest('.gkts-rule');
    $(this).closest('.gkts-group').remove();
    updateSortOrderFields();
    updateTotal($rule);
  });

  $('#gkts-products').on('click', '.gkts-remove-rule', function(){
    var $config = $(this).closest('.gkts-product-config');
    $(this).closest('.gkts-rule').remove();
    updateSortOrderFields();
    updateEmptyStates();
    $config.find('.gkts-empty').first().toggle($config.find('.gkts-rules > .gkts-rule').length === 0);
  });

  $('#gkts-products').on('input change', '.gkts-group-count', function(){
    updateTotal($(this).closest('.gkts-rule'));
  });

  $('#gkts-products').on('input', '.gkts-rule-name', function(){
    updateRuleTitle($(this).closest('.gkts-rule'));
  });

  $('#gkts-products').on('change', '.gkts-product-select', function(){
    var $config = $(this).closest('.gkts-product-config');
    updateProductTitle($config);
    refreshVariations($config);
  });

  $('#gkts-settings-form').on('submit', function(){
    // Reihenfolge explizit mitsenden, damit PHP nicht von Array-Schlüsseln abhängt.
    updateSortOrderFields();
    updateEmptyStates();
  });

  initEnhanced($(document));
  initProductSearch();
  initSortables($(document));
  updateSortOrderFields();
  updateEmptyStates();

  // Bereits vorhandene Produktkarten aktualisieren ihre Variantenlisten einzeln.
  $('#gkts-products .gkts-product-config').each(function(){
    var $config = $(this);
    updateProductTitle($config);
    var productId = currentProductId($config);
    if (productId) {
      // Vorhandene Optionen aus PHP bleiben zunächst sichtbar; AJAX füllt sie anschließend frisch nach.
      loadVariations(productId, function(options){
        $config.find('.gkts-variation-select').each(function(){
          var current = $(this).val();
          fillVariationSelect($(this), options, current);
        });
      });
    }
  });
});