/* Tiny translation system. Usage:
 *   HTML:  <span data-i18n="key"></span>   <input data-i18n-placeholder="key">
 *   JS:    I18N.t('key', { name: 'value' })   ->  replaces {name}
 * The chosen language is stored in localStorage ("platescan_lang").
 */
(function () {
  'use strict';

  var STORE_KEY = 'platescan_lang';

  var dict = {
    en: {
      'nav.scan': 'Scan & Register',
      'nav.vehicles': 'Registry',
      'nav.manual': 'Manual & API',
      'foot.text': 'Vehicle registration with live exchange rates.',
      'page.index': 'Scan & Register',
      'page.vehicles': 'Registered vehicles',
      'page.manual': 'Manual & API',

      'hero.title': 'Scan a plate, register the vehicle',
      'hero.sub': 'Point the camera at a plate or upload a photo. We read the plate; you confirm the vehicle details.',

      'scan.title': 'Plate scanner',
      'tab.camera': 'Camera',
      'tab.upload': 'Upload photo',
      'cam.idle': 'Camera is off. Start the camera to scan a plate.',
      'cam.hint': 'Fit the plate inside the frame, keep the camera steady and avoid glare.',
      'cam.start': 'Start camera',
      'cam.capture': 'Capture & scan',
      'cam.switch': 'Switch camera',
      'cam.stop': 'Stop camera',
      'cam.starting': 'Starting camera…',
      'cam.denied': 'Camera access was blocked. Allow the camera in your browser\'s address bar and try again, or use Upload photo.',
      'cam.notfound': 'No camera found. Connect a camera or use Upload photo.',
      'cam.insecure': 'The camera only works on a secure (https://) connection. Open this site over https or use Upload photo.',
      'cam.error': 'The camera could not be started. Close other apps that use it and try again.',
      'upload.drop': 'Drop a plate photo here or click to browse',
      'upload.hint': 'JPG, PNG or WebP, up to 8 MB',
      'upload.badtype': 'Only JPG, PNG or WebP images are supported.',
      'upload.toolarge': 'That image is larger than 8 MB. Choose a smaller photo.',
      'upload.readfail': 'That image could not be opened. Try a different photo.',
      'scan.retake': 'Scan another',
      'scan.scanning': 'Reading plate…',
      'scan.confidence': 'Confidence',
      'scan.verify': 'Low confidence. Check every character before registering.',
      'scan.filled': 'Plate filled into the registration form.',
      'scan.engine.browser': 'Read by the in-browser OCR engine.',
      'scan.browser.loading': 'Server scanner unavailable. Loading in-browser OCR…',
      'scan.browser.reading': 'Reading plate in your browser…',

      'err.PLATE_UNREADABLE': 'Unable to read license plate clearly. Please adjust lighting or reposition the camera.',
      'err.NO_PLATE_DETECTED': 'No license plate detected. Fill the frame with the plate, reduce glare and hold the camera steady.',
      'err.OCR_UNAVAILABLE': 'The scanner is temporarily unavailable. Try again shortly or type the plate manually.',
      'err.BROWSER_OCR_FAILED': 'The in-browser scanner could not load. Check your connection or type the plate manually.',
      'err.RATE_LIMITED': 'Too many requests. Please wait {s} seconds and try again.',
      'err.CSRF_INVALID': 'Your session has expired. Please refresh the page and try again.',
      'err.NETWORK': 'Network problem. Check your connection and try again.',
      'err.SERVER_ERROR': 'Something went wrong on our side. Please try again in a moment.',
      'err.PLATE_EXISTS': 'This plate number is already registered.',
      'err.VALIDATION_FAILED': 'Please correct the highlighted fields.',
      'err.RATES_UNAVAILABLE': 'Exchange rates are temporarily unavailable. Please try again shortly.',
      'err.INVALID_IMAGE': 'That file is not a valid JPG, PNG or WebP image.',
      'err.IMAGE_TOO_LARGE': 'The image is too large. Please use a smaller photo.',
      'err.NO_IMAGE': 'No image received. Capture a frame or choose a photo first.',
      'err.INVALID_JSON': 'The request body must be valid JSON.',
      'err.METHOD_NOT_ALLOWED': 'This endpoint does not accept that request method.',

      'reg.title': 'Vehicle registration',
      'reg.subtitle': 'Enter the vehicle details yourself so the record is exact. The plate is filled in by the scanner, and you can edit it.',
      'f.plate': 'License plate number',
      'f.plate.ph': 'e.g. WXY 1234',
      'f.owner': 'Owner full name',
      'f.phone': 'Phone number',
      'f.phone.ph': '+60 12-345 6789',
      'f.make': 'Make / brand',
      'f.model': 'Model',
      'f.color': 'Colour',
      'f.body': 'Body type',
      'f.body.select': 'Select type',
      'f.currency': 'Payment currency',
      'type.Sedan': 'Sedan',
      'type.SUV': 'SUV',
      'type.Hatchback': 'Hatchback',
      'type.Pickup': 'Pickup',
      'type.Van': 'Van',
      'type.Motorcycle': 'Motorcycle',
      'type.Lorry': 'Lorry',
      'reg.submit': 'Register vehicle',
      'reg.saving': 'Saving…',
      'reg.reset': 'Clear form',
      'reg.success': 'Vehicle {plate} registered. Fee: {fee}.',
      'val.plate_number': 'Enter a valid plate number: 2 to 12 letters or digits.',
      'val.owner_name': 'Enter the owner\'s full name (2 to 100 characters, letters only).',
      'val.phone': 'Enter a valid phone number, e.g. +60 12-345 6789.',
      'val.make': 'Enter the vehicle make.',
      'val.model': 'Enter the vehicle model.',
      'val.color': 'Enter the vehicle colour, e.g. Silver.',
      'val.body_type': 'Choose a body type from the list.',
      'val.currency': 'Choose a currency from the list.',

      'fee.base': 'Registration fee (USD)',
      'fee.rate': 'Exchange rate',
      'fee.total': 'You pay',
      'fee.loading': 'Loading exchange rates…',
      'fee.source.live': 'Live rate from {p}.',
      'fee.source.cache': 'Rate from {p}, cached for up to 1 hour.',
      'fee.source.stale': 'Live rates are unavailable, so the most recent saved rate from {p} is used.',
      'fee.updated': 'Updated {time}.',

      'veh.title': 'Registered vehicles',
      'veh.subtitle': 'Search by plate, owner, make or model. Filter by body type or registration date.',
      'veh.search': 'Search',
      'veh.search.ph': 'Plate, owner, make or model',
      'veh.type': 'Body type',
      'veh.type.all': 'All types',
      'veh.from': 'Registered from',
      'veh.to': 'Registered to',
      'veh.reset': 'Reset',
      'veh.count': '{n} vehicles',
      'veh.count.one': '1 vehicle',
      'veh.empty': 'No vehicles match your search. Try a shorter search or clear the filters.',
      'veh.loading': 'Loading…',
      'th.plate': 'Plate',
      'th.owner': 'Owner',
      'th.phone': 'Phone',
      'th.vehicle': 'Vehicle',
      'th.color': 'Colour',
      'th.type': 'Type',
      'th.fee.usd': 'Fee (USD)',
      'th.fee.paid': 'Fee paid',
      'th.date': 'Registered',
      'page.prev': 'Previous',
      'page.next': 'Next',
      'page.info': 'Page {p} of {n}',

      'man.title': 'Manual & API reference',
      'man.intro': 'How to scan and register a vehicle, and how the JSON API works.',
      'man.h.start': 'Quick start',
      'man.s1': 'Open Scan & Register and choose Camera or Upload photo.',
      'man.s2': 'Camera: press Start camera, allow access when the browser asks, fit the plate inside the yellow frame and press Capture & scan.',
      'man.s3': 'Upload: drop a photo of the plate on the drop area, or click it to choose a file. Scanning starts by itself.',
      'man.s4': 'Check the scanned plate and its confidence. The plate is filled into the form; edit it if any character is wrong.',
      'man.s5': 'Enter the owner, phone, make, model, colour and body type, choose your currency and press Register vehicle.',
      'man.h.tips': 'Getting a good scan',
      'man.t1': 'Fill the yellow frame with the plate. Move closer rather than zooming into a blurry image.',
      'man.t2': 'Avoid glare and shadows across the plate. Tilt the camera slightly if the plate reflects a light.',
      'man.t3': 'Hold the camera steady for a moment before pressing Capture & scan.',
      'man.t4': 'If a scan fails twice, type the plate manually. The field is always editable.',
      'man.h.fees': 'Fees and currencies',
      'man.fees.p': 'The registration fee is fixed in US dollars. When you pick another currency, the amount is converted with live exchange rates. Rates are saved on the server and reused for one hour; if the rate services are down, the latest saved rates are used. The server recalculates the fee when you register, so the amount stored is always correct.',
      'man.h.search': 'Searching the registry',
      'man.search.p': 'On the Registry page, type a plate, owner name, make or model. Combine the search with a body type or a registration date range. Results are paginated; each row shows the fee in US dollars and in the currency the owner paid in.',
      'man.h.privacy': 'Privacy',
      'man.privacy.p': 'Phone numbers are stored in full but shown masked in the public registry.',
      'man.h.api': 'API reference',
      'man.api.intro': 'All endpoints live under /api/ and always answer with JSON.',
      'man.api.format': 'Success responses look like { "status": "success", "data": { ... } }. Errors look like { "status": "error", "error": { "code": "...", "message": "..." } }.',
      'man.api.csrf': 'POST requests must send the CSRF token from the page\'s csrf-token meta tag in the X-CSRF-Token header, with the same session cookie.',
      'man.api.limit': 'Each IP address may make 30 requests per minute to each endpoint. Beyond that the API answers 429 with a retry_after value.',
      'man.api.scan.d': 'Reads a plate from an image. Send the image as multipart/form-data in the field "image" (JPG, PNG or WebP, up to 8 MB). The box is relative to the image (0 to 1).',
      'man.api.register.d': 'Validates and stores a registration. Send a JSON body. The fee is calculated on the server.',
      'man.api.rates.d': 'Returns USD-based exchange rates from the MySQL cache, refreshing from open.er-api.com and then api.frankfurter.dev when the cache is older than one hour.',
      'man.api.vehicles.d': 'Searches and paginates registered vehicles. All parameters are optional: q (plate, owner, make or model), type, date_from, date_to (YYYY-MM-DD) and page.',
      'man.h.errors': 'Error codes',
      'man.th.code': 'Code',
      'man.th.http': 'HTTP',
      'man.th.meaning': 'Meaning',

      'cur.USD': 'USD - US Dollar',
      'cur.MYR': 'MYR - Malaysian Ringgit',
      'cur.SGD': 'SGD - Singapore Dollar',
      'cur.EUR': 'EUR - Euro',
      'cur.GBP': 'GBP - British Pound',
      'cur.JPY': 'JPY - Japanese Yen',
      'cur.CNY': 'CNY - Chinese Yuan',
      'cur.HKD': 'HKD - Hong Kong Dollar',
      'cur.AUD': 'AUD - Australian Dollar',
      'cur.NZD': 'NZD - New Zealand Dollar',
      'cur.CAD': 'CAD - Canadian Dollar',
      'cur.CHF': 'CHF - Swiss Franc',
      'cur.IDR': 'IDR - Indonesian Rupiah',
      'cur.THB': 'THB - Thai Baht',
      'cur.PHP': 'PHP - Philippine Peso',
      'cur.INR': 'INR - Indian Rupee',
      'cur.KRW': 'KRW - South Korean Won'
    },

    zh: {
      'nav.scan': '扫描与登记',
      'nav.vehicles': '车辆登记册',
      'nav.manual': '手册与 API',
      'foot.text': '车辆登记，实时汇率换算。',
      'page.index': '扫描与登记',
      'page.vehicles': '已登记车辆',
      'page.manual': '手册与 API',

      'hero.title': '扫描车牌，登记车辆',
      'hero.sub': '将摄像头对准车牌，或上传一张照片。系统识别车牌，车辆信息由您自己确认填写。',

      'scan.title': '车牌扫描',
      'tab.camera': '摄像头',
      'tab.upload': '上传照片',
      'cam.idle': '摄像头未开启。点击“开启摄像头”开始扫描。',
      'cam.hint': '让车牌完整位于取景框内，保持稳定并避开反光。',
      'cam.start': '开启摄像头',
      'cam.capture': '拍摄并识别',
      'cam.switch': '切换摄像头',
      'cam.stop': '关闭摄像头',
      'cam.starting': '正在启动摄像头…',
      'cam.denied': '摄像头权限被拒绝。请在浏览器地址栏中允许使用摄像头后重试，或改用“上传照片”。',
      'cam.notfound': '未找到可用的摄像头。请连接摄像头，或改用“上传照片”。',
      'cam.insecure': '摄像头仅能在安全连接（https://）下使用。请通过 https 访问本站，或改用“上传照片”。',
      'cam.error': '无法启动摄像头。请关闭其他占用摄像头的应用后重试。',
      'upload.drop': '将车牌照片拖到这里，或点击选择文件',
      'upload.hint': 'JPG、PNG 或 WebP，最大 8 MB',
      'upload.badtype': '仅支持 JPG、PNG 或 WebP 图片。',
      'upload.toolarge': '图片超过 8 MB，请选择较小的照片。',
      'upload.readfail': '无法打开该图片，请换一张照片。',
      'scan.retake': '扫描另一张',
      'scan.scanning': '正在识别车牌…',
      'scan.confidence': '置信度',
      'scan.verify': '置信度较低，登记前请逐个字符核对。',
      'scan.filled': '车牌已填入登记表单。',
      'scan.engine.browser': '由浏览器内置识别引擎读取。',
      'scan.browser.loading': '服务器识别不可用，正在加载浏览器识别引擎…',
      'scan.browser.reading': '正在浏览器中识别车牌…',

      'err.PLATE_UNREADABLE': '无法清晰识别车牌，请调整光线或重新对准摄像头。',
      'err.NO_PLATE_DETECTED': '未检测到车牌。请让车牌充满取景框、减少反光并保持稳定。',
      'err.OCR_UNAVAILABLE': '识别服务暂时不可用，请稍后重试，或手动输入车牌。',
      'err.BROWSER_OCR_FAILED': '浏览器识别引擎无法加载。请检查网络连接，或手动输入车牌。',
      'err.RATE_LIMITED': '请求过于频繁，请等待 {s} 秒后重试。',
      'err.CSRF_INVALID': '会话已过期，请刷新页面后重试。',
      'err.NETWORK': '网络出现问题，请检查连接后重试。',
      'err.SERVER_ERROR': '服务器出了点问题，请稍后再试。',
      'err.PLATE_EXISTS': '该车牌号码已登记。',
      'err.VALIDATION_FAILED': '请修正标出的字段。',
      'err.RATES_UNAVAILABLE': '汇率暂时不可用，请稍后重试。',
      'err.INVALID_IMAGE': '该文件不是有效的 JPG、PNG 或 WebP 图片。',
      'err.IMAGE_TOO_LARGE': '图片太大，请使用较小的照片。',
      'err.NO_IMAGE': '未收到图片，请先拍摄或选择一张照片。',
      'err.INVALID_JSON': '请求内容必须是有效的 JSON。',
      'err.METHOD_NOT_ALLOWED': '该接口不接受此请求方法。',

      'reg.title': '车辆登记',
      'reg.subtitle': '请亲自填写车辆信息，确保记录准确。车牌由扫描器自动填入，也可以手动修改。',
      'f.plate': '车牌号码',
      'f.plate.ph': '例如 WXY 1234',
      'f.owner': '车主姓名',
      'f.phone': '电话号码',
      'f.phone.ph': '+60 12-345 6789',
      'f.make': '品牌',
      'f.model': '车型',
      'f.color': '颜色',
      'f.body': '车身类型',
      'f.body.select': '请选择类型',
      'f.currency': '缴费币种',
      'type.Sedan': '轿车',
      'type.SUV': 'SUV',
      'type.Hatchback': '两厢车',
      'type.Pickup': '皮卡',
      'type.Van': '厢式货车',
      'type.Motorcycle': '摩托车',
      'type.Lorry': '货车',
      'reg.submit': '登记车辆',
      'reg.saving': '正在保存…',
      'reg.reset': '清空表单',
      'reg.success': '车辆 {plate} 已登记，费用：{fee}。',
      'val.plate_number': '请输入有效的车牌号码：2 至 12 位字母或数字。',
      'val.owner_name': '请输入车主全名（2 至 100 个字符，仅限文字）。',
      'val.phone': '请输入有效的电话号码，例如 +60 12-345 6789。',
      'val.make': '请输入车辆品牌。',
      'val.model': '请输入车辆型号。',
      'val.color': '请输入车辆颜色，例如 银色。',
      'val.body_type': '请从列表中选择车身类型。',
      'val.currency': '请从列表中选择币种。',

      'fee.base': '登记费用（美元）',
      'fee.rate': '汇率',
      'fee.total': '应付金额',
      'fee.loading': '正在获取汇率…',
      'fee.source.live': '实时汇率，来源：{p}。',
      'fee.source.cache': '汇率来源：{p}，缓存最长 1 小时。',
      'fee.source.stale': '暂时无法获取实时汇率，使用 {p} 最近保存的汇率。',
      'fee.updated': '更新于 {time}。',

      'veh.title': '已登记车辆',
      'veh.subtitle': '按车牌、车主、品牌或车型搜索，并可按车身类型或登记日期筛选。',
      'veh.search': '搜索',
      'veh.search.ph': '车牌、车主、品牌或车型',
      'veh.type': '车身类型',
      'veh.type.all': '全部类型',
      'veh.from': '登记起始日期',
      'veh.to': '登记截止日期',
      'veh.reset': '重置',
      'veh.count': '共 {n} 辆',
      'veh.count.one': '共 1 辆',
      'veh.empty': '没有符合条件的车辆。请缩短搜索词或清除筛选条件。',
      'veh.loading': '加载中…',
      'th.plate': '车牌',
      'th.owner': '车主',
      'th.phone': '电话',
      'th.vehicle': '车辆',
      'th.color': '颜色',
      'th.type': '类型',
      'th.fee.usd': '费用（美元）',
      'th.fee.paid': '已付费用',
      'th.date': '登记时间',
      'page.prev': '上一页',
      'page.next': '下一页',
      'page.info': '第 {p} 页，共 {n} 页',

      'man.title': '使用手册与 API 参考',
      'man.intro': '如何扫描并登记车辆，以及 JSON API 的使用方法。',
      'man.h.start': '快速上手',
      'man.s1': '打开“扫描与登记”页面，选择“摄像头”或“上传照片”。',
      'man.s2': '摄像头：点击“开启摄像头”，在浏览器提示时允许访问，让车牌完整位于黄色取景框内，然后点击“拍摄并识别”。',
      'man.s3': '上传：把车牌照片拖到上传区域，或点击该区域选择文件。识别会自动开始。',
      'man.s4': '查看识别出的车牌和置信度。车牌会自动填入表单；如有字符错误请直接修改。',
      'man.s5': '填写车主、电话、品牌、车型、颜色和车身类型，选择币种，然后点击“登记车辆”。',
      'man.h.tips': '如何获得清晰的扫描结果',
      'man.t1': '让车牌充满黄色取景框。宁可靠近一些，也不要放大模糊的画面。',
      'man.t2': '避免车牌上出现反光和阴影。如果车牌反射灯光，请稍微倾斜摄像头。',
      'man.t3': '点击“拍摄并识别”前，先让摄像头稳定片刻。',
      'man.t4': '如果连续两次识别失败，请手动输入车牌，该字段始终可以编辑。',
      'man.h.fees': '费用与币种',
      'man.fees.p': '登记费用以美元固定计价。选择其他币种时，金额会按实时汇率换算。汇率保存在服务器上并复用一小时；如果汇率服务不可用，则使用最近保存的汇率。登记时服务器会重新计算费用，因此保存的金额始终准确。',
      'man.h.search': '搜索登记册',
      'man.search.p': '在“车辆登记册”页面输入车牌、车主姓名、品牌或车型进行搜索，也可以同时按车身类型或登记日期范围筛选。结果分页显示，每行同时显示美元费用和车主实际支付的币种金额。',
      'man.h.privacy': '隐私',
      'man.privacy.p': '电话号码完整保存，但在公开的登记册中会被部分隐藏。',
      'man.h.api': 'API 参考',
      'man.api.intro': '所有接口位于 /api/ 下，并始终返回 JSON。',
      'man.api.format': '成功响应形如 { "status": "success", "data": { ... } }；错误响应形如 { "status": "error", "error": { "code": "...", "message": "..." } }。',
      'man.api.csrf': 'POST 请求必须在 X-CSRF-Token 请求头中携带页面 csrf-token meta 标签里的令牌，并使用同一个会话 Cookie。',
      'man.api.limit': '每个 IP 地址对每个接口每分钟最多请求 30 次，超过后接口返回 429 及 retry_after 值。',
      'man.api.scan.d': '从图片中识别车牌。以 multipart/form-data 上传图片，字段名为 “image”（JPG、PNG 或 WebP，最大 8 MB）。box 为相对于图片的比例坐标（0 到 1）。',
      'man.api.register.d': '校验并保存登记信息。请发送 JSON 请求体。费用由服务器计算。',
      'man.api.rates.d': '返回以美元为基准的汇率。优先使用 MySQL 缓存；缓存超过一小时时，先从 open.er-api.com 刷新，失败后改用 api.frankfurter.dev。',
      'man.api.vehicles.d': '搜索并分页查询已登记车辆。所有参数均为可选：q（车牌、车主、品牌或车型）、type、date_from、date_to（YYYY-MM-DD）和 page。',
      'man.h.errors': '错误代码',
      'man.th.code': '代码',
      'man.th.http': 'HTTP',
      'man.th.meaning': '含义',

      'cur.USD': 'USD - 美元',
      'cur.MYR': 'MYR - 马来西亚令吉',
      'cur.SGD': 'SGD - 新加坡元',
      'cur.EUR': 'EUR - 欧元',
      'cur.GBP': 'GBP - 英镑',
      'cur.JPY': 'JPY - 日元',
      'cur.CNY': 'CNY - 人民币',
      'cur.HKD': 'HKD - 港元',
      'cur.AUD': 'AUD - 澳元',
      'cur.NZD': 'NZD - 新西兰元',
      'cur.CAD': 'CAD - 加拿大元',
      'cur.CHF': 'CHF - 瑞士法郎',
      'cur.IDR': 'IDR - 印尼盾',
      'cur.THB': 'THB - 泰铢',
      'cur.PHP': 'PHP - 菲律宾比索',
      'cur.INR': 'INR - 印度卢比',
      'cur.KRW': 'KRW - 韩元'
    }
  };

  var lang = 'en';

  function detect() {
    try {
      var saved = localStorage.getItem(STORE_KEY);
      if (saved && dict[saved]) return saved;
    } catch (e) { /* storage blocked: fall through */ }
    return String(navigator.language || 'en').toLowerCase().indexOf('zh') === 0 ? 'zh' : 'en';
  }

  /** Translate a key; {name} placeholders are replaced from `vars`. Unknown keys return the key itself. */
  function t(key, vars) {
    var s = (dict[lang] && dict[lang][key]);
    if (s === undefined) s = dict.en[key];
    if (s === undefined) return key;
    return vars ? s.replace(/\{(\w+)\}/g, function (m, k) { return k in vars ? vars[k] : m; }) : s;
  }

  function apply() {
    var i;
    var nodes = document.querySelectorAll('[data-i18n]');
    for (i = 0; i < nodes.length; i++) nodes[i].textContent = t(nodes[i].getAttribute('data-i18n'));
    nodes = document.querySelectorAll('[data-i18n-placeholder]');
    for (i = 0; i < nodes.length; i++) nodes[i].setAttribute('placeholder', t(nodes[i].getAttribute('data-i18n-placeholder')));

    document.documentElement.lang = lang === 'zh' ? 'zh-CN' : 'en';
    var titleKey = document.body && document.body.getAttribute('data-title-key');
    if (titleKey) document.title = t(titleKey) + ' | ' + (document.body.getAttribute('data-app') || '');

    var btns = document.querySelectorAll('.lang [data-lang]');
    for (i = 0; i < btns.length; i++) btns[i].setAttribute('aria-pressed', btns[i].getAttribute('data-lang') === lang ? 'true' : 'false');
  }

  function setLang(next) {
    if (!dict[next] || next === lang) return;
    lang = next;
    try { localStorage.setItem(STORE_KEY, lang); } catch (e) { /* ignore */ }
    apply();
    document.dispatchEvent(new CustomEvent('langchange', { detail: { lang: lang } }));
  }

  lang = detect();

  window.I18N = {
    t: t,
    getLang: function () { return lang; },
    setLang: setLang,
    /** BCP-47 locale for Intl formatting. */
    locale: function () { return lang === 'zh' ? 'zh-CN' : 'en-US'; }
  };

  document.addEventListener('DOMContentLoaded', function () {
    var btns = document.querySelectorAll('.lang [data-lang]');
    for (var i = 0; i < btns.length; i++) {
      btns[i].addEventListener('click', function () { setLang(this.getAttribute('data-lang')); });
    }
    apply();
  });
})();
