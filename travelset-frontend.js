jQuery(function($){
  if (typeof GK_TRAVELSET === 'undefined') return;

  var RULES = GK_TRAVELSET.rules || {};
  var RULE_ORDER = GK_TRAVELSET.ruleOrder || [];
  var TEXTS = GK_TRAVELSET.texts || {};
  var $form = $('form.variations_form');
  var $wrap = $('.gkts-travelset');
  if (!$form.length || !$wrap.length) return;

  var $grid = $wrap.find('.gkts-grid');
  var $hint = $wrap.find('.gkts-hint');
  var $headline = $wrap.find('.gkts-headline');
  var openIndex = null;
  var currentVariationId = 0;
  var cache = {};

  sortNativeVariationOptions();
  setTimeout(sortNativeVariationOptions, 300);

  function normalizeValue(v){
    return String(v == null ? '' : v).trim().toLowerCase();
  }

  function sortNativeVariationOptions(){
    if (!RULE_ORDER.length) return;
    var variations = $form.data('product_variations') || [];
    if (!Array.isArray(variations) || !variations.length) return;

    var byId = {};
    variations.forEach(function(v){
      if (v && v.variation_id) byId[String(v.variation_id)] = v;
    });

    $form.find('select[name^="attribute_"]').each(function(){
      var $select = $(this);
      var attrName = $select.attr('name');
      if (!attrName) return;

      var wanted = [];
      RULE_ORDER.forEach(function(id){
        var v = byId[String(id)];
        var value = v && v.attributes ? v.attributes[attrName] : '';
        var key = normalizeValue(value);
        if (key && wanted.indexOf(key) === -1) wanted.push(key);
      });
      if (!wanted.length) return;

      var selected = String($select.val() || '');
      var placeholder = $select.children('option[value=""]').first().detach();
      var options = $select.children('option').detach().get();
      options.sort(function(a,b){
        var ak = normalizeValue($(a).val());
        var bk = normalizeValue($(b).val());
        var ai = wanted.indexOf(ak);
        var bi = wanted.indexOf(bk);
        if (ai < 0) ai = 999999;
        if (bi < 0) bi = 999999;
        if (ai === bi) return 0;
        return ai - bi;
      });
      if (placeholder.length) $select.append(placeholder);
      options.forEach(function(opt){ $select.append(opt); });
      if (selected) $select.val(selected);
    });
  }

  function naturalCompare(a, b){
    return String(a || '').localeCompare(String(b || ''), undefined, {numeric:true, sensitivity:'base'});
  }

  function closeAll(){
    $grid.find('.gkts-dd-menu').hide();
    $grid.find('.gkts-dd').attr('aria-expanded', 'false');
    openIndex = null;
  }

  $(document).on('click.gkts', function(){ closeAll(); });

  $wrap.on('click', '.gkts-dd-toggle', function(e){
    e.preventDefault();
    e.stopPropagation();
    var $cell = $(this).closest('.gkts-cell');
    var index = parseInt($cell.attr('data-index'), 10);
    var $menu = $cell.find('.gkts-dd-menu');
    if (openIndex === index) {
      $menu.hide();
      $cell.find('.gkts-dd').attr('aria-expanded', 'false');
      openIndex = null;
      return;
    }
    closeAll();
    $menu.show();
    $cell.find('.gkts-dd').attr('aria-expanded', 'true');
    openIndex = index;
  });

  function format(str, n){
    return String(str || '').replace('%d', n);
  }

  function expandRule(rule){
    var slots = [];
    (rule.groups || []).forEach(function(group){
      var count = Math.max(1, parseInt(group.count || 1, 10));
      for (var i=1; i<=count; i++) {
        slots.push({
          label: group.label || '',
          groupIndex: i,
          groupCount: count,
          categoryIds: group.categoryIds || []
        });
      }
    });
    return slots;
  }

  function renderSlots(slots){
    $grid.empty();
    slots.forEach(function(slot, idx){
      var i = idx + 1;
      var label = slot.label || ('Parfum ' + i);
      if (slot.groupCount > 1) label += ' ' + slot.groupIndex;

      var $cell = $('<div class="gkts-cell">').attr('data-index', i);
      $('<div class="gkts-slot-label">').text(label).appendTo($cell);
      var $dd = $('<div class="gkts-dd" role="combobox" aria-expanded="false">').appendTo($cell);
      var $button = $('<button type="button" class="gkts-dd-toggle">').appendTo($dd);
      $('<span class="gkts-dd-current">').text(TEXTS.loading || 'Lade Optionen …').appendTo($button);
      $('<span class="gkts-dd-caret">').text('▾').appendTo($button);
      $('<div class="gkts-dd-menu" role="listbox">').appendTo($dd);
      $('<input type="hidden" class="gkts-value">').attr('name', 'gk_parfum[]').val('').appendTo($cell);
      $cell.data('categories', slot.categoryIds || []);
      $grid.append($cell);
    });
  }

  function fillCell($cell, items){
    var $menu = $cell.find('.gkts-dd-menu').empty();
    var $current = $cell.find('.gkts-dd-current');
    var $hidden = $cell.find('.gkts-value');
    $hidden.val('');
    $current.text(TEXTS.placeholder || 'Bitte auswählen …');

    if (!items || !items.length) {
      $menu.append($('<div class="gkts-dd-muted">').text(TEXTS.noResults || 'Keine Optionen gefunden'));
      return;
    }

    items.slice().sort(function(a,b){ return naturalCompare(a.text, b.text); }).forEach(function(item){
      var $opt = $('<div class="gkts-dd-item" role="option" tabindex="-1">').text(item.text).attr('data-id', item.id);
      $opt.on('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        $hidden.val(String(item.id));
        $current.text(item.text);
        $menu.hide();
        $cell.find('.gkts-dd').attr('aria-expanded', 'false');
        openIndex = null;
      });
      $menu.append($opt);
    });
  }

  function fetchList(categoryIds, done){
    var normalized = (categoryIds || []).map(function(v){ return parseInt(v,10) || 0; }).filter(Boolean).sort(function(a,b){return a-b;});
    var key = normalized.join(',');
    if (cache[key]) { done(cache[key]); return; }

    $.post(GK_TRAVELSET.ajax, {
      action: 'gkts_frontend_lists',
      nonce: GK_TRAVELSET.nonce,
      product_id: GK_TRAVELSET.productId || 0,
      sets: [normalized]
    }).done(function(resp){
      var list = [];
      if (resp && resp.success && resp.data && resp.data.lists && resp.data.lists[0]) list = resp.data.lists[0];
      cache[key] = list;
      done(list);
    }).fail(function(){ done([]); });
  }

  function applyRule(variationId){
    currentVariationId = parseInt(variationId || 0, 10) || 0;
    closeAll();

    if (!currentVariationId) {
      $grid.empty();
      $headline.text('Wähle deine Parfums');
      $hint.text(TEXTS.chooseVariantFirst || 'Bitte zuerst die Variante wählen …').show();
      return;
    }

    var rule = RULES[String(currentVariationId)];
    if (!rule) {
      $grid.empty();
      $headline.text('Wähle deine Parfums');
      $hint.text(TEXTS.notConfigured || 'Für diese Variante ist noch keine Travel-Set-Regel hinterlegt.').show();
      return;
    }

    var slots = expandRule(rule);
    $headline.text(format(TEXTS.headline || 'Wähle deine %d Parfums', slots.length));
    $hint.text(rule.name || '').toggle(!!rule.name);
    renderSlots(slots);

    $grid.find('.gkts-cell').each(function(){
      var $cell = $(this);
      fetchList($cell.data('categories') || [], function(items){
        if (currentVariationId !== parseInt(variationId, 10)) return;
        fillCell($cell, items);
      });
    });
  }

  $form.on('woocommerce_update_variation_values', function(){
    setTimeout(sortNativeVariationOptions, 0);
  });

  $form.on('found_variation', function(e, variation){
    applyRule(variation && variation.variation_id ? variation.variation_id : 0);
  });

  $form.on('reset_data hide_variation', function(){
    applyRule(0);
  });

  $form.on('change', 'select[name^="attribute_"]', function(){
    var id = parseInt($form.find('input.variation_id').val() || 0, 10);
    if (!id) applyRule(0);
  });

  setTimeout(function(){
    var id = parseInt($form.find('input.variation_id').val() || 0, 10);
    applyRule(id);
  }, 250);
});