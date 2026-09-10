(function () {
  'use strict';

  if (!window.aapAdmin) {
    return;
  }

  var number = new Intl.NumberFormat('ja-JP');
	var dateLabel = new Intl.DateTimeFormat('ja-JP', {
	  year: 'numeric',
	  month: 'long',
	  day: 'numeric',
	  weekday: 'short'
	});

	function localDate(date) {
	  return date.getFullYear() + '-' + String(date.getMonth() + 1).padStart(2, '0') + '-' + String(date.getDate()).padStart(2, '0');
	}

	function parseLocalDate(value) {
	  var parts = value.split('-').map(Number);
	  return new Date(parts[0], parts[1] - 1, parts[2]);
	}

	function shiftDate(value, amount) {
	  var date = parseLocalDate(value);
	  date.setDate(date.getDate() + amount);
	  return localDate(date);
	}

	function todayValue() {
	  return window.aapAdmin.today || localDate(new Date());
	}

  function clear(element) {
    while (element && element.firstChild) {
      element.removeChild(element.firstChild);
    }
  }

  function formatDuration(value) {
    var seconds = Math.max(0, Math.round(Number(value) || 0));
    var minutes = Math.floor(seconds / 60);
    var remainder = seconds % 60;
    return minutes > 0 ? minutes + '分' + (remainder > 0 ? remainder + '秒' : '') : remainder + '秒';
  }

  function metricValue(metric, item) {
    if (metric.value === null) return '集計中';
    if (item.format === 'duration') return formatDuration(metric.value);
    if (item.format === 'percent') return number.format(metric.value) + '%';
    if (item.format === 'pages') return number.format(metric.value) + 'ページ';
    return number.format(metric.value) + (item.suffix || '');
  }

  function changeText(metric, item) {
    if (metric.value === null) return '終了した訪問を集計中';
    if (metric.previous === null || (!item.zeroIsData && metric.previous === 0 && metric.value > 0)) return '前期間はデータなし';
    if (metric.difference === 0) return '前期間と同じ';

    var arrow = metric.direction === 'up' ? '↑' : metric.direction === 'down' ? '↓' : '→';
    if (item.compare === 'duration') {
      return arrow + ' 前期間より' + formatDuration(Math.abs(metric.difference)) + (metric.difference > 0 ? '増' : '減');
    }
    if (item.compare === 'points') {
      return arrow + ' 前期間より' + number.format(Math.abs(metric.difference)) + 'ポイント' + (metric.difference > 0 ? '増' : '減');
    }
    if (item.compare === 'pages') {
      return arrow + ' 前期間より' + number.format(Math.abs(metric.difference)) + 'ページ' + (metric.difference > 0 ? '増' : '減');
    }
    if (metric.change === null) return '前期間と同じ';
    var sign = metric.change > 0 ? '+' : '';
    return arrow + ' 前期間比 ' + sign + metric.change + '%';
  }

  function comparisonClass(metric, item) {
    if (metric.direction === 'unavailable' || metric.direction === 'flat') return 'is-' + metric.direction;
    var isGood = metric.direction === 'up';
    if (item.lowerIsBetter) isGood = !isGood;
    return isGood ? 'is-up' : 'is-down';
  }

  function renderMetrics(container, metrics, compact, quality) {
    var target = container.querySelector('[data-aap-metrics]');
    if (!target) return;
    clear(target);

	var items = [
	  { key: 'visitors', label: compact ? '訪問者' : '訪問者数', suffix: '人', help: 'この期間に訪れたブラウザーの数です。' },
      { key: 'pageviews', label: compact ? 'PV' : '閲覧回数（PV）', suffix: '' },
	  { key: 'average_engaged_seconds', label: '平均閲覧時間', format: 'duration', compare: 'duration', zeroIsData: true, help: 'ページが画面に表示されていた有効時間を、終了した訪問ごとに平均した概算値です。' },
	  { key: 'bounce_rate', label: '直帰率', format: 'percent', compare: 'points', zeroIsData: true, lowerIsBetter: true, help: '1ページだけ見て終了した訪問の割合です。現在閲覧中の訪問は含みません。' },
	  { key: 'pages_per_visit', label: '1訪問あたりPV', format: 'pages', compare: 'pages', help: '1回の訪問で平均何ページ見られたかを表します。' }
    ];
    if (compact) items = items.slice(0, 2);

    items.forEach(function (item) {
      var metric = metrics[item.key];
      var card = document.createElement('div');
      card.className = 'aap-metric-card' + (!compact && ['visitors', 'pageviews'].includes(item.key) ? ' is-primary' : '');

      var label = document.createElement('span');
      label.className = 'aap-metric-label';
      label.textContent = item.label;
	  if (item.help && !compact) {
		var help = document.createElement('button');
		help.type = 'button';
		help.className = 'aap-help';
		help.dataset.help = item.help;
		help.setAttribute('aria-label', item.label + 'の説明');
		help.textContent = '?';
		label.appendChild(help);
	  }

      var value = document.createElement('strong');
      value.className = 'aap-metric-value';
      value.textContent = metricValue(metric, item);
      if (!compact && metric.value !== null && (item.suffix || item.format === 'pages' || item.format === 'percent')) {
        var unit = item.suffix || (item.format === 'pages' ? 'ページ' : '%');
        value.textContent = value.textContent.slice(0, -unit.length);
        var unitLabel = document.createElement('span');
        unitLabel.className = 'aap-metric-unit';
        unitLabel.textContent = unit;
        value.appendChild(unitLabel);
      }

      var comparison = document.createElement('span');
      comparison.className = 'aap-change ' + comparisonClass(metric, item);
      comparison.textContent = changeText(metric, item);

      card.append(label, value, comparison);
      target.appendChild(card);
    });

	var summary = container.querySelector('[data-aap-metrics-summary]');
	if (summary && metrics.visits) {
	  var parts = ['訪問 ' + number.format(metrics.visits.value) + '回'];
	  if (quality) {
		if (quality.ended_visits > 0) {
		  parts.push('閲覧品質は終了した' + number.format(quality.ended_visits) + '訪問から集計');
		} else {
		  parts.push('閲覧品質は終了した訪問を集計中');
		}
		if (quality.partial) parts.push('有効時間の計測開始後のデータ');
		if (quality.provisional) parts.push('本日の数値は暫定値');
	  }
	  summary.textContent = parts.join(' ・ ');
	}
  }

  function isDailyRows(rows) {
	return rows.length > 1 && rows.every(function (row) { return /^\d{4}-\d{2}-\d{2}$/.test(row.date); });
  }

  function niceScale(maximum) {
	var rough = Math.max(1, maximum) / 4;
	var magnitude = Math.pow(10, Math.floor(Math.log10(rough)));
	var fraction = rough / magnitude;
	var niceFraction = fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10;
	var step = Math.max(1, niceFraction * magnitude);
	return { step: step, maximum: Math.max(step * 2, Math.ceil(maximum / step) * step) };
  }

  function pointHeading(row, daily) {
	if (daily) return dateLabel.format(parseLocalDate(row.date));
	return String(row.label).replace(/時$/, ':00');
  }

  function highlightTime(container, date) {
    container.querySelectorAll('[data-aap-time]').forEach(function (node) {
      node.classList.toggle('is-highlighted', node.dataset.aapTime === date);
    });
  }

  function renderChart(container, rows, compact) {
    var target = container.querySelector('[data-aap-chart]');
    if (!target) return;
    target.aapChartRows = rows;
    target.aapChartWidth = Math.round(target.clientWidth);
    if (!compact && window.ResizeObserver && !target.aapResizeObserver) {
      target.aapResizeObserver = new ResizeObserver(function () {
        if (Math.round(target.clientWidth) !== target.aapChartWidth) renderChart(container, target.aapChartRows, false);
      });
      target.aapResizeObserver.observe(target);
    }
    clear(target);
	target.className = 'aap-chart-wrap';
	delete target.dataset.tooltipPinned;

    if (!rows.some(function (row) { return row.visitors > 0 || row.pageviews > 0; })) {
      var empty = document.createElement('p');
      empty.className = 'aap-chart-empty';
      empty.textContent = 'この期間に記録されたアクセスはありません。別の期間も確認できます。';
      target.appendChild(empty);
      return;
    }

    var values = rows.map(function (row) { return compact ? row.visitors : Math.max(row.visitors, row.pageviews); });
	var scale = niceScale(Math.max.apply(Math, values.concat([0])));
    var width = compact ? 520 : Math.max(320, Math.min(840, target.clientWidth || 840));
	var height = compact ? 120 : 250;
    var padLeft = compact ? 8 : 52;
	var padRight = compact ? 8 : 24;
	var padTop = compact ? 12 : 28;
	var padBottom = compact ? 26 : 34;
    var usableWidth = width - padLeft - padRight;
    var usableHeight = height - padTop - padBottom;
	var dailySeries = isDailyRows(rows);
	var slotWidth = usableWidth / Math.max(1, rows.length);
	var points = rows.map(function (row, index) {
      var x = padLeft + (index + .5) * slotWidth;
      var y = padTop + usableHeight - (row.visitors / scale.maximum) * usableHeight;
      return { x: x, y: y, row: row };
    });

    var ns = 'http://www.w3.org/2000/svg';
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', compact ? '訪問者数の推移。縦軸は訪問者数です。' : '訪問者数と閲覧回数の推移。青は訪問者数（人）、薄い青は閲覧回数（PV）。両方ともゼロ基準で重ねています。');
    if (!compact) {
      var legend = document.createElement('div');
      legend.className = 'aap-chart-legend';
      [['visitors', '濃い青：訪問者数（人）'], ['pageviews', '薄い青：閲覧回数（PV）']].forEach(function (entry) {
        var label = document.createElement('span');
        label.className = 'is-' + entry[0];
        label.textContent = entry[1];
        legend.appendChild(label);
      });
      var note = document.createElement('span');
      note.className = 'aap-chart-legend-note';
      note.textContent = '重ねて表示（合計ではありません）';
      legend.appendChild(note);
      target.appendChild(legend);
    }
    svg.classList.add('aap-chart');

	if (!compact) {
	  for (var tick = 0; tick <= scale.maximum; tick += scale.step) {
		var tickY = padTop + usableHeight - (tick / scale.maximum) * usableHeight;
		var grid = document.createElementNS(ns, 'line');
		grid.setAttribute('x1', padLeft);
		grid.setAttribute('x2', width - padRight);
		grid.setAttribute('y1', tickY);
		grid.setAttribute('y2', tickY);
		grid.setAttribute('class', tick === 0 ? 'aap-chart-baseline' : 'aap-chart-gridline');
		svg.appendChild(grid);
		var tickText = document.createElementNS(ns, 'text');
		tickText.setAttribute('x', padLeft - 10);
		tickText.setAttribute('y', tickY + 4);
		tickText.setAttribute('text-anchor', 'end');
		tickText.setAttribute('class', 'aap-chart-y-label');
		tickText.textContent = number.format(tick);
		svg.appendChild(tickText);
	  }
	}

	if (points.length) {
	  var bubble = document.createElement('div');
	  bubble.className = 'aap-chart-bubble';
	  bubble.hidden = true;
	  bubble.setAttribute('role', 'status');
	  target.appendChild(bubble);

      points.forEach(function (point, index) {
		var showDetails = function (pinned) {
		  highlightTime(container, point.row.date);
		  clear(bubble);
		  var heading = document.createElement('strong');
		  heading.textContent = pointHeading(point.row, dailySeries);
		  var valuesLine = document.createElement('span');
		  valuesLine.textContent = '訪問者 ' + number.format(point.row.visitors) + '人　PV ' + number.format(point.row.pageviews);
		  bubble.append(heading, valuesLine);
		  bubble.hidden = false;
		  var chartRect = svg.getBoundingClientRect();
		  var targetRect = target.getBoundingClientRect();
		  bubble.style.left = (chartRect.left - targetRect.left + point.x / width * chartRect.width) + 'px';
		  bubble.style.top = (chartRect.top - targetRect.top + point.y / height * chartRect.height) + 'px';
		  bubble.classList.toggle('is-left-edge', index < 2);
		  bubble.classList.toggle('is-right-edge', index > points.length - 3);
		  target.dataset.tooltipPinned = pinned ? '1' : '0';
		};
		var hideDetails = function () {
		  if (target.dataset.tooltipPinned !== '1') {
			bubble.hidden = true;
			highlightTime(container, null);
		  }
		};

		var barWidth = compact ? Math.max(3, Math.min(18, slotWidth * .58)) : Math.min(44, slotWidth * .7);
		var visitorBar = document.createElementNS(ns, 'rect');
		visitorBar.setAttribute('class', 'aap-chart-bar');
		visitorBar.setAttribute('data-aap-time', point.row.date);
		visitorBar.setAttribute('x', point.x - (compact ? barWidth / 2 : barWidth * .36));
		visitorBar.setAttribute('y', point.y);
		visitorBar.setAttribute('width', compact ? barWidth : barWidth * .72);
		visitorBar.setAttribute('height', height - padBottom - point.y);
		visitorBar.setAttribute('rx', '2');
		if (!compact) {
		  var pvBar = document.createElementNS(ns, 'rect');
		  var pvHeight = point.row.pageviews / scale.maximum * usableHeight;
		  pvBar.setAttribute('class', 'aap-chart-bar is-pageviews');
		  pvBar.setAttribute('data-aap-time', point.row.date);
		  pvBar.setAttribute('x', point.x - barWidth / 2);
		  pvBar.setAttribute('y', height - padBottom - pvHeight);
		  pvBar.setAttribute('width', barWidth);
		  pvBar.setAttribute('height', pvHeight);
		  pvBar.setAttribute('rx', '2');
		  svg.appendChild(pvBar);
		}
		svg.appendChild(visitorBar);

		var hit = document.createElementNS(ns, 'rect');
		hit.setAttribute('x', point.x - slotWidth / 2);
		hit.setAttribute('y', padTop);
		hit.setAttribute('width', slotWidth);
		hit.setAttribute('height', usableHeight);
		hit.setAttribute('class', 'aap-chart-hit');
		hit.setAttribute('fill', 'transparent');
		hit.setAttribute('stroke', 'none');
		hit.setAttribute('tabindex', '0');
		hit.setAttribute('role', dailySeries && !compact ? 'button' : 'img');
		hit.setAttribute('aria-label', pointHeading(point.row, dailySeries) + '、訪問者' + point.row.visitors + '人、PV' + point.row.pageviews);
		hit.addEventListener('mouseenter', function () { showDetails(false); });
		hit.addEventListener('mouseleave', hideDetails);
		hit.addEventListener('focus', function () { showDetails(false); });
		hit.addEventListener('blur', hideDetails);
		hit.addEventListener('click', function () {
		  var touchInteraction = window.matchMedia && window.matchMedia('(hover: none)').matches;
		  showDetails(touchInteraction);
		  if (dailySeries && !compact) drillIntoDay(container, point.row.date);
		});
		hit.addEventListener('keydown', function (event) {
		  if (event.key === 'Enter' || event.key === ' ') {
			event.preventDefault();
			hit.dispatchEvent(new MouseEvent('click'));
		  }
		});
		svg.appendChild(hit);

        var showCompactLabel = compact && (dailySeries || index === 0 || index === rows.length - 1 || index % 6 === 0);
        var showFullLabel = !compact && (rows.length <= 10 || index % Math.ceil(rows.length / 7) === 0 || index === rows.length - 1);
        if (showCompactLabel || showFullLabel) {
          var text = document.createElementNS(ns, 'text');
          text.setAttribute('x', point.x);
          text.setAttribute('y', height - (compact ? 7 : 9));
          text.setAttribute('text-anchor', 'middle');
          text.setAttribute('class', 'aap-chart-label' + (compact ? ' is-compact' : ''));
          text.textContent = point.row.label;
          svg.appendChild(text);
        }
      });
	  target.insertBefore(svg, bubble);
    } else {
	  target.appendChild(svg);
	}

	var helper = document.createElement('p');
	helper.className = 'aap-chart-helper';
	helper.textContent = compact ? (dailySeries ? '直近7日間の訪問者推移' : '時間別の訪問者推移') : (dailySeries ? '棒を選ぶと、その日の時間別表示へ移動します。' : '棒にマウスを重ねるかタップすると、人数とPVを確認できます。');
	target.appendChild(helper);
  }

  function renderTimeseriesList(container, rows, compact) {
	var target = container.querySelector('[data-aap-timeseries-list]');
	if (!target || compact) return;
	clear(target);
	var daily = isDailyRows(rows);
	var disclosure = container.querySelector('[data-aap-timeseries-disclosure]');
	if (disclosure) disclosure.querySelector('summary').textContent = daily ? '日ごとの数字・時間別への切り替え' : '時間ごとの数字';
	var activeRows = rows.filter(function (row) { return row.visitors > 0 || row.pageviews > 0; });
	var expanded = false;

	function draw() {
	  clear(target);
	  var heading = document.createElement('div');
	  heading.className = 'aap-timeseries-heading';
	  var title = document.createElement('h3');
	  title.textContent = daily ? '日別アクセス' : '時間別アクセス';
	  var summary = document.createElement('span');
	  summary.textContent = 'アクセスあり ' + activeRows.length + '/' + rows.length;
	  heading.append(title, summary);
	  target.appendChild(heading);
	  var definition = document.createElement('p');
	  definition.className = 'aap-timeseries-definition';
	  definition.textContent = '平均閲覧時間は、その時間・日に開始した終了済み訪問を基準にしています。';
	  target.appendChild(definition);

	  if (!activeRows.length && !expanded) {
		var empty = document.createElement('p');
		empty.className = 'aap-timeseries-empty';
		empty.textContent = 'この期間にはまだアクセスがありません。';
		target.appendChild(empty);
	  }

	  var visibleRows;
	  if (expanded) {
		visibleRows = rows;
	  } else if (daily && rows.length <= 7) {
		visibleRows = rows;
	  } else {
		visibleRows = activeRows.slice(daily ? -10 : 0);
	  }

	  if (visibleRows.length) {
		var list = document.createElement('div');
		list.className = 'aap-timeseries-list';
		visibleRows.forEach(function (row) {
		  var item = document.createElement(daily ? 'button' : 'div');
		  if (daily) item.type = 'button';
		  item.className = 'aap-timeseries-row' + (row.visitors === 0 && row.pageviews === 0 ? ' is-zero' : '');
		  item.dataset.aapTime = row.date;
		  item.addEventListener('mouseenter', function () { highlightTime(container, row.date); });
		  item.addEventListener('mouseleave', function () { highlightTime(container, null); });
		  item.addEventListener('focus', function () { highlightTime(container, row.date); });
		  item.addEventListener('blur', function () { highlightTime(container, null); });
		  var time = document.createElement('strong');
		  time.textContent = daily ? dateLabel.format(parseLocalDate(row.date)).replace(/^\d{4}年/, '') : String(row.label).replace(/時$/, ':00');
		  if (daily) {
			var action = document.createElement('span');
			action.className = 'aap-row-action';
			action.textContent = '時間別を見る ›';
			time.appendChild(action);
		  }
		  var visitors = document.createElement('span');
		  visitors.className = 'aap-timeseries-visitors';
		  visitors.textContent = '訪問者 ' + number.format(row.visitors) + '人';
		  var pageviews = document.createElement('span');
		  pageviews.className = 'aap-timeseries-pageviews';
		  pageviews.textContent = 'PV ' + number.format(row.pageviews);
		  var engagement = document.createElement('span');
		  engagement.className = 'aap-timeseries-engagement';
		  engagement.textContent = '平均閲覧 ' + (row.average_engaged_seconds === null || typeof row.average_engaged_seconds === 'undefined' ? '集計中' : formatDuration(row.average_engaged_seconds));
		  item.append(time, visitors, pageviews, engagement);
		  if (daily) item.addEventListener('click', function () { drillIntoDay(container, row.date); });
		  list.appendChild(item);
		});
		target.appendChild(list);
	  }

	  var canExpand = rows.length > visibleRows.length || expanded;
	  if (canExpand) {
		var toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'button-link aap-timeseries-toggle';
		toggle.textContent = expanded ? 'アクセスがあった' + (daily ? '日だけ' : '時間だけ') + '表示' : 'すべての' + (daily ? '日を見る' : '時間を見る');
		toggle.addEventListener('click', function () { expanded = !expanded; draw(); });
		target.appendChild(toggle);
	  }
	}
	draw();
  }

	function setActiveRangeButton(container, range) {
	  container.querySelectorAll('[data-range]').forEach(function (button) {
		var active = button.dataset.range === range;
		button.classList.toggle('is-active', active);
		button.setAttribute('aria-pressed', active ? 'true' : 'false');
	  });
	}

	function drillIntoDay(container, date) {
	  container.aapParentPeriod = {
		start: container.dataset.activeStart,
		end: container.dataset.activeEnd,
		mode: container.dataset.periodMode || '7d'
	  };
	  container.aapFocusPeriod = true;
	  setDayView(container, date);
	}

	function setDayView(container, date) {
	  if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || date > todayValue()) return;
	  var start = container.querySelector('[data-aap-start]');
	  var end = container.querySelector('[data-aap-end]');
	  var custom = container.querySelector('[data-aap-custom-period]');
	  if (!start || !end) return;
	  start.value = date;
	  end.value = date;
	  container.dataset.activeDate = date;
	  container.dataset.periodMode = 'day';
	  if (custom) custom.hidden = true;
	  container.dataset.range = 'custom';
	  setActiveRangeButton(container, date === todayValue() ? 'today' : (date === shiftDate(todayValue(), -1) ? 'yesterday' : ''));
	  load(container);
	}

	function setCustomPeriod(container, startDate, endDate, mode) {
	  var start = container.querySelector('[data-aap-start]');
	  var end = container.querySelector('[data-aap-end]');
	  var custom = container.querySelector('[data-aap-custom-period]');
	  if (!start || !end || startDate > endDate || endDate > todayValue()) return;
	  start.value = startDate;
	  end.value = endDate;
	  container.dataset.range = 'custom';
	  container.dataset.periodMode = mode;
	  if (custom) custom.hidden = true;
	  setActiveRangeButton(container, mode === 'day' ? (startDate === todayValue() ? 'today' : (startDate === shiftDate(todayValue(), -1) ? 'yesterday' : '')) : mode);
	  load(container);
	}

	function latestPeriod(container) {
	  var mode = container.dataset.periodMode || '7d';
	  if (mode === 'day') return setDayView(container, todayValue());
	  container.dataset.range = mode;
	  setActiveRangeButton(container, mode);
	  load(container);
	}

	function movePeriod(container, direction) {
	  var mode = container.dataset.periodMode || 'day';
	  var start = container.dataset.activeStart;
	  var end = container.dataset.activeEnd;
	  if (!start || !end || mode === 'custom' || (direction > 0 && end >= todayValue())) return;
	  if (mode === 'day') return setDayView(container, shiftDate(start, direction));
	  if (mode === 'month') {
		var current = parseLocalDate(start);
		var monthStart = new Date(current.getFullYear(), current.getMonth() + direction, 1);
		var monthEnd = new Date(monthStart.getFullYear(), monthStart.getMonth() + 1, 0);
		var monthEndValue = localDate(monthEnd);
		return setCustomPeriod(container, localDate(monthStart), monthEndValue > todayValue() ? todayValue() : monthEndValue, 'month');
	  }
	  var days = mode === '30d' ? 30 : 7;
	  setCustomPeriod(container, shiftDate(start, days * direction), shiftDate(end, days * direction), mode);
	}

	function renderCalendar(container, monthValue) {
	  var target = container.querySelector('[data-aap-calendar]');
	  if (!target) return;
	  clear(target);
	  var month = parseLocalDate(monthValue.slice(0, 7) + '-01');
	  var monthKey = localDate(month).slice(0, 7);
	  var expanded = !!container.aapCalendarExpanded;
	  var anchor = container.dataset.activeEnd || todayValue();
	  var stripEnd = shiftDate(anchor, 6 - parseLocalDate(anchor).getDay());
	  var stripStart = shiftDate(stripEnd, -13);
	  var header = document.createElement('div');
	  header.className = 'aap-calendar-heading';
	  var title = document.createElement('strong');
	  title.textContent = month.getFullYear() + '年' + (month.getMonth() + 1) + '月';
	  if (!expanded) title.textContent = stripStart.replace(/-/g, '/') + '〜' + stripEnd.slice(5).replace('-', '/');
	  title.setAttribute('aria-live', 'polite');
	  function monthButton(direction) {
		var button = document.createElement('button');
		button.type = 'button';
		button.textContent = direction < 0 ? '‹ 前月' : '翌月 ›';
		button.setAttribute('data-aap-calendar-month', String(direction));
		button.setAttribute('aria-label', direction < 0 ? 'カレンダーの前月を表示' : 'カレンダーの翌月を表示');
		button.disabled = direction > 0 && monthKey >= todayValue().slice(0, 7);
		button.addEventListener('click', function () {
		  container.aapCalendarExpanded = true;
		  var nextMonth = localDate(new Date(month.getFullYear(), month.getMonth() + direction, 1));
		  renderCalendar(container, nextMonth);
		  var replacement = target.querySelector('[data-aap-calendar-month="' + direction + '"]');
		  if (replacement.disabled) replacement = target.querySelector('[data-aap-calendar-month="-1"]');
		  replacement.focus();
		});
		return button;
	  }
	  header.append(monthButton(-1), title, monthButton(1));
	  target.appendChild(header);
	  var grid = document.createElement('div');
	  grid.className = 'aap-calendar-days';
	  ['日', '月', '火', '水', '木', '金', '土'].forEach(function (day) {
		var label = document.createElement('span');
		label.className = 'aap-calendar-weekday';
		label.textContent = day;
		grid.appendChild(label);
	  });
	  if (expanded) for (var blank = 0; blank < month.getDay(); blank++) grid.appendChild(document.createElement('span'));
	  var lastDay = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
	  for (var day = 1; day <= (expanded ? lastDay : 14); day++) {
		var value = expanded ? monthKey + '-' + String(day).padStart(2, '0') : shiftDate(stripStart, day - 1);
		var button = document.createElement('button');
		button.type = 'button';
		button.textContent = Number(value.slice(8));
		button.dataset.aapCalendarDate = value;
		button.disabled = value > todayValue();
		var selected = value >= container.dataset.activeStart && value <= container.dataset.activeEnd;
		button.classList.toggle('is-selected', selected);
		button.setAttribute('aria-pressed', selected ? 'true' : 'false');
		button.setAttribute('aria-label', dateLabel.format(parseLocalDate(value)) + 'の時間別アクセスを見る');
		if (value === todayValue()) button.setAttribute('aria-current', 'date');
		button.addEventListener('click', function (event) {
		  var chosen = event.currentTarget.dataset.aapCalendarDate;
		  if (container.dataset.activeStart !== container.dataset.activeEnd) drillIntoDay(container, chosen);
		  else {
			container.aapFocusPeriod = true;
			setDayView(container, chosen);
		  }
		});
		grid.appendChild(button);
	  }
	  target.appendChild(grid);
	  var toggle = document.createElement('button');
	  toggle.type = 'button';
	  toggle.className = 'aap-calendar-toggle';
	  toggle.textContent = expanded ? '2週間表示に戻す' : '月全体から選ぶ';
	  toggle.setAttribute('data-aap-calendar-toggle', '');
	  toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
	  toggle.addEventListener('click', function () {
		container.aapCalendarExpanded = !expanded;
		renderCalendar(container, anchor);
		target.querySelector('[data-aap-calendar-toggle]').focus();
	  });
	  target.appendChild(toggle);
	  var hint = document.createElement('p');
	  hint.textContent = '日付を押すと時間別に表示します。青色は表示中の期間、枠線は今日です。';
	  target.appendChild(hint);
	}

	function updateDateNavigation(container, period) {
	  var navigation = container.querySelector('[data-aap-date-navigation]');
	  if (!navigation) return;
	  var mode = container.dataset.periodMode || (period.start === period.end ? 'day' : container.dataset.range || 'custom');
	  if (period.start === period.end) mode = 'day';
	  var isSingleDay = mode === 'day' || period.start === period.end;
	  if (!isSingleDay) container.aapParentPeriod = null;
	  container.dataset.periodMode = mode;
	  navigation.hidden = false;
	  container.dataset.activeStart = period.start;
	  container.dataset.activeEnd = period.end;
	  renderCalendar(container, period.end);
	  if (isSingleDay) container.dataset.activeDate = period.start;
	  var label = navigation.querySelector('[data-aap-period-label]');
	  var previous = navigation.querySelector('[data-aap-previous-period]');
	  var next = navigation.querySelector('[data-aap-next-period]');
	  var returnLatest = navigation.querySelector('[data-aap-return-latest]');
	  var picker = navigation.querySelector('[data-aap-day-picker]');
	  var pickerInput = navigation.querySelector('[data-aap-day-picker-input]');
	  if (label) {
		label.textContent = isSingleDay ? dateLabel.format(parseLocalDate(period.start)) : (mode === 'month' ? period.start.slice(0, 4) + '年' + Number(period.start.slice(5, 7)) + '月' : period.start.replace(/-/g, '/') + ' 〜 ' + period.end.replace(/-/g, '/'));
	  }
	  if (previous) {
		previous.hidden = mode === 'custom';
		previous.setAttribute('aria-label', mode === 'day' ? '前の日を表示' : mode === 'month' ? '前の月を表示' : '前の' + (mode === '30d' ? '30日' : '7日') + 'を表示');
	  }
	  if (next) {
		next.hidden = mode === 'custom';
		next.disabled = period.end >= todayValue();
		next.setAttribute('aria-label', mode === 'day' ? '次の日を表示' : mode === 'month' ? '次の月を表示' : '次の' + (mode === '30d' ? '30日' : '7日') + 'を表示');
	  }
	  if (picker) picker.hidden = !isSingleDay;
	  if (pickerInput) {
		pickerInput.value = period.start;
		if (!isSingleDay) pickerInput.hidden = true;
	  }
	  if (returnLatest) {
		returnLatest.hidden = mode === 'custom' || period.end >= todayValue();
		returnLatest.textContent = isSingleDay ? '今日へ戻る' : '最新期間へ戻る';
	  }
	  if (isSingleDay) setActiveRangeButton(container, period.start === todayValue() ? 'today' : (period.start === shiftDate(todayValue(), -1) ? 'yesterday' : ''));
	  var back = navigation.querySelector('[data-aap-back-to-period]');
	  if (!back) {
		back = document.createElement('button');
		back.type = 'button';
		back.className = 'button-link';
		back.setAttribute('data-aap-back-to-period', '');
		back.addEventListener('click', function () {
		  var parent = container.aapParentPeriod;
		  if (!parent) return;
		  container.aapFocusPeriod = true;
		  setCustomPeriod(container, parent.start, parent.end, parent.mode);
		});
		navigation.appendChild(back);
	  }
	  var parent = container.aapParentPeriod;
	  back.hidden = !isSingleDay || !parent;
	  if (parent) back.textContent = '‹ ' + parent.start.replace(/-/g, '/') + '〜' + parent.end.replace(/-/g, '/') + ' に戻る';
	  if (container.aapFocusPeriod && label) {
		label.setAttribute('tabindex', '-1');
		label.focus();
		container.aapFocusPeriod = false;
	  }
	}

  function renderBarItems(target, rows) {
    rows.forEach(function (row) {
      var item = document.createElement('div');
      item.className = 'aap-bar-item';
      var heading = document.createElement('div');
      heading.className = 'aap-bar-heading';
      var label = document.createElement('span');
      label.textContent = row.label;
      var value = document.createElement('strong');
      value.textContent = row.percent + '%';
      heading.append(label, value);
      var track = document.createElement('div');
      track.className = 'aap-bar-track';
      track.setAttribute('role', 'img');
      track.setAttribute('aria-label', row.label + ' ' + row.percent + '%');
      var bar = document.createElement('span');
      bar.style.width = Math.min(100, row.percent) + '%';
      track.appendChild(bar);
      item.append(heading, track);
      target.appendChild(item);
    });
  }

	function renderSources(container, sources, details) {
    var target = container.querySelector('[data-aap-sources]');
    if (!target) return;
    clear(target);
	var detailTarget = container.querySelector('[data-aap-source-details]');
	if (detailTarget) clear(detailTarget);

    if (!sources.length) {
      target.textContent = window.aapAdmin.strings.empty;
      return;
    }

    renderBarItems(target, sources);

	if (!detailTarget) return;
	[
	  { key: 'search', title: '検索エンジン' },
	  { key: 'social', title: 'SNS内訳' }
	].forEach(function (group) {
	  if (!details[group.key] || !details[group.key].length) return;
	  var block = document.createElement('div');
	  var heading = document.createElement('h3');
	  heading.textContent = group.title;
	  var values = document.createElement('p');
	  values.textContent = details[group.key].map(function (item) { return item.label + ' ' + item.value; }).join('　');
	  block.append(heading, values);
	  detailTarget.appendChild(block);
	});
  }

  function renderDevices(container, devices) {
    var target = container.querySelector('[data-aap-devices]');
    if (!target) return;
    clear(target);

    if (!devices.length) {
      target.textContent = window.aapAdmin.strings.empty;
      return;
    }

    renderBarItems(target, devices);
  }

  function renderExclusions(container, exclusions) {
    var details = container.querySelector('[data-aap-exclusions]');
    if (!details) return;
    var total = details.querySelector('[data-aap-exclusions-total]');
    var target = details.querySelector('[data-aap-exclusion-items]');
    var data = exclusions || { total: 0, items: [] };
    if (total) total.textContent = number.format(data.total || 0) + '件';
    if (!target) return;
    clear(target);

    if (!data.items || !data.items.length) {
      target.textContent = 'この期間に除外したアクセスはありません。';
      return;
    }

    data.items.forEach(function (entry) {
      var row = document.createElement('div');
      var label = document.createElement('span');
      label.textContent = entry.label;
      var value = document.createElement('strong');
      value.textContent = number.format(entry.value) + '件';
      row.append(label, value);
      target.appendChild(row);
    });
  }

  function renderPages(container, pages, compact) {
    var target = container.querySelector('[data-aap-pages]');
    if (!target) return;
    clear(target);

    if (!pages.length) {
      if (!compact) target.textContent = window.aapAdmin.strings.empty;
      return;
    }

    if (compact) {
      var summary = document.createElement('p');
      summary.className = 'aap-widget-page';
      summary.textContent = 'よく見られているページ：' + pages[0].title + '（' + number.format(pages[0].pageviews) + 'PV）';
      target.appendChild(summary);
      return;
    }

    var list = document.createElement('ol');
    list.className = 'aap-ranking';
    pages.forEach(function (page) {
      var item = document.createElement('li');
      var title = document.createElement('span');
      title.textContent = page.title;
      var value = document.createElement('strong');
      value.textContent = number.format(page.pageviews) + ' PV';
      item.append(title, value);
      list.appendChild(item);
    });
    target.appendChild(list);
  }

  function load(container) {
    var status = container.querySelector('[data-aap-status]');
    var range = container.dataset.range || '7d';
	var compact = container.hasAttribute('data-dashboard-widget');
	var params = new URLSearchParams({ range: range, context: compact ? 'dashboard' : 'full' });
    if (range === 'custom') {
      params.set('start', container.querySelector('[data-aap-start]').value);
      params.set('end', container.querySelector('[data-aap-end]').value);
    }
    if (status) {
      status.hidden = false;
      status.textContent = window.aapAdmin.strings.loading;
    }
	if (container.aapRequestController) container.aapRequestController.abort();
	container.aapRequestController = new AbortController();

    window.fetch(window.aapAdmin.reportEndpoint + '?' + params.toString(), {
      credentials: 'same-origin',
	  cache: 'no-store',
	  headers: { 'X-WP-Nonce': window.aapAdmin.nonce },
	  signal: container.aapRequestController.signal
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok) throw new Error(data.message || window.aapAdmin.strings.error);
        return data;
      });
    }).then(function (data) {
	  renderMetrics(container, compact ? data.dashboard.metrics : data.metrics, compact, compact ? null : (data.quality || null));
      renderChart(container, data.timeseries, compact);
	  renderTimeseriesList(container, data.timeseries, compact);
	  renderSources(container, data.sources, data.source_details || { search: [], social: [] });
      renderPages(container, data.pages, compact);
	  renderDevices(container, data.devices || []);
	  renderExclusions(container, data.exclusions || null);
	  var month = container.querySelector('[data-aap-month]');
	  if (month && data.dashboard) month.textContent = '今月の訪問者：' + number.format(data.dashboard.month_visitors) + '人';
	  var updated = container.querySelector('[data-aap-updated]');
      if (updated) updated.textContent = '最終更新 ' + data.updated_at;
	  var sampleBadge = container.querySelector('[data-aap-sample-badge]');
	  if (sampleBadge) sampleBadge.hidden = !data.sample;
	  if (!compact) updateDateNavigation(container, data.period);
	  if (status) {
		status.hidden = data.metrics.pageviews.value > 0;
		if (!status.hidden) status.textContent = window.aapAdmin.strings.empty;
	  }
    }).catch(function (error) {
	  if (error.name === 'AbortError') return;
      if (status) status.textContent = error.message || window.aapAdmin.strings.error;
    });
  }

  document.querySelectorAll('[data-aap-report]').forEach(function (container) {
	container.querySelectorAll('[data-range]').forEach(function (button) {
	  button.setAttribute('aria-pressed', button.classList.contains('is-active') ? 'true' : 'false');
	});
    container.querySelectorAll('[data-range]').forEach(function (button) {
      button.addEventListener('click', function () {
		setActiveRangeButton(container, button.dataset.range);
        container.dataset.range = button.dataset.range;
		if (button.dataset.range === 'today' || button.dataset.range === 'yesterday') {
		  container.dataset.periodMode = 'day';
		} else if (button.dataset.range !== 'custom') {
		  container.dataset.periodMode = button.dataset.range;
		}
        var custom = container.querySelector('[data-aap-custom-period]');
		if (custom) {
		  custom.hidden = button.dataset.range !== 'custom';
		  if (button.dataset.range === 'custom') {
			var endInput = container.querySelector('[data-aap-end]');
			var startInput = container.querySelector('[data-aap-start]');
			if (endInput && !endInput.value) {
			  var today = new Date();
			  var weekAgo = new Date(today);
			  weekAgo.setDate(today.getDate() - 6);
			  endInput.value = localDate(today);
			  startInput.value = localDate(weekAgo);
			}
		  }
		}
        if (button.dataset.range !== 'custom') load(container);
      });
    });
    var apply = container.querySelector('[data-aap-apply-period]');
    if (apply) apply.addEventListener('click', function () {
	  container.dataset.periodMode = 'custom';
	  var custom = container.querySelector('[data-aap-custom-period]');
	  if (custom) custom.hidden = true;
	  load(container);
	});
	var previousPeriod = container.querySelector('[data-aap-previous-period]');
	if (previousPeriod) previousPeriod.addEventListener('click', function () { movePeriod(container, -1); });
	var nextPeriod = container.querySelector('[data-aap-next-period]');
	if (nextPeriod) nextPeriod.addEventListener('click', function () { movePeriod(container, 1); });
	var returnLatest = container.querySelector('[data-aap-return-latest]');
	if (returnLatest) returnLatest.addEventListener('click', function () { latestPeriod(container); });
	var dayPicker = container.querySelector('[data-aap-day-picker-input]');
	var openDayPicker = container.querySelector('[data-aap-open-day-picker]');
	if (openDayPicker && dayPicker) openDayPicker.addEventListener('click', function () {
	  dayPicker.hidden = false;
	  dayPicker.focus();
	  if (typeof dayPicker.showPicker === 'function') {
		try { dayPicker.showPicker(); } catch (error) { /* The visible native field remains available. */ }
	  }
	});
	if (dayPicker) dayPicker.addEventListener('change', function () {
	  if (dayPicker.value) setDayView(container, dayPicker.value);
	  dayPicker.hidden = true;
	});
    load(container);
  });

  var toggle = document.querySelector('[data-aap-mobile-toggle]');
  var panel = document.querySelector('[data-aap-mobile-panel]');
  if (toggle && panel) {
    toggle.addEventListener('click', function () {
      panel.hidden = !panel.hidden;
      if (!panel.hidden) {
        var qrTarget = panel.querySelector('[data-aap-qr]');
        var urlInput = panel.querySelector('[data-aap-url]');
        if (qrTarget && urlInput && !qrTarget.hasChildNodes() && typeof window.qrcode === 'function') {
          var qr = window.qrcode(0, 'M');
          qr.addData(urlInput.value);
          qr.make();
		  var qrDocument = new DOMParser().parseFromString(qr.createSvgTag({ cellSize: 4, margin: 4, scalable: true }), 'image/svg+xml');
		  if (!qrDocument.querySelector('parsererror')) {
			qrTarget.appendChild(document.importNode(qrDocument.documentElement, true));
		  }
        }
      }
    });
  }
  var copy = document.querySelector('[data-aap-copy]');
  if (copy) {
    copy.addEventListener('click', function () {
      var input = document.querySelector('[data-aap-url]');
      if (!input) return;
	  var done = function () {
        copy.textContent = 'コピーしました';
        window.setTimeout(function () { copy.textContent = 'URLをコピー'; }, 1600);
	  };
	  if (navigator.clipboard && window.isSecureContext) {
		navigator.clipboard.writeText(input.value).then(done);
	  } else {
		input.select();
		document.execCommand('copy');
		done();
	  }
    });
  }
})();
