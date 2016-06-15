THREE.OrbitControls = function(object, domElement) {
    this.object = object;
    this.domElement = (domElement !== undefined) ? domElement : document;
    this.enabled = true;
    this.target = new THREE.Vector3();
    this.center = this.target;
    this.noZoom = false;
    this.zoomSpeed = 1.0;
    this.minDistance = 0;
    this.maxDistance = Infinity;
    this.noRotate = false;
    this.rotateSpeed = 1.0;
    this.noPan = false;
    this.keyPanSpeed = 7.0;
    this.autoRotate = false;
    this.autoRotateSpeed = 2.0;
    this.minPolarAngle = 0;
    this.maxPolarAngle = Math.PI;
    this.noKeys = false;
    this.keys = {
        LEFT: 37,
        UP: 38,
        RIGHT: 39,
        BOTTOM: 40
    };
    var scope = this;
    var EPS = 0.000001;
    var rotateStart = new THREE.Vector2();
    var rotateEnd = new THREE.Vector2();
    var rotateDelta = new THREE.Vector2();
    var panStart = new THREE.Vector2();
    var panEnd = new THREE.Vector2();
    var panDelta = new THREE.Vector2();
    var dollyStart = new THREE.Vector2();
    var dollyEnd = new THREE.Vector2();
    var dollyDelta = new THREE.Vector2();
    var phiDelta = 0;
    var thetaDelta = 0;
    var scale = 1;
    var pan = new THREE.Vector3();
    var lastPosition = new THREE.Vector3();
    var STATE = {
        NONE: -1,
        ROTATE: 0,
        DOLLY: 1,
        PAN: 2,
        TOUCH_ROTATE: 3,
        TOUCH_DOLLY: 4,
        TOUCH_PAN: 5
    };
    var state = STATE.NONE;
    var changeEvent = {
        type: 'change'
    };
    this.rotateLeft = function(angle) {
        if (angle === undefined) {
            angle = getAutoRotationAngle();
        }
        thetaDelta -= angle;
    };
    this.rotateUp = function(angle) {
        if (angle === undefined) {
            angle = getAutoRotationAngle();
        }
        phiDelta -= angle;
    };
    this.panLeft = function(distance) {
        var panOffset = new THREE.Vector3();
        var te = this.object.matrix.elements;
        panOffset.set(te[0], te[1], te[2]);
        panOffset.multiplyScalar(-distance);
        pan.add(panOffset);
    };
    this.panUp = function(distance) {
        var panOffset = new THREE.Vector3();
        var te = this.object.matrix.elements;
        panOffset.set(te[4], te[5], te[6]);
        panOffset.multiplyScalar(distance);
        pan.add(panOffset);
    };
    this.pan = function(delta) {
        var element = scope.domElement === document ? scope.domElement.body : scope.domElement;
        if (scope.object.fov !== undefined) {
            var position = scope.object.position;
            var offset = position.clone().sub(scope.target);
            var targetDistance = offset.length();
            targetDistance *= Math.tan((scope.object.fov / 2) * Math.PI / 180.0);
            scope.panLeft(2 * delta.x * targetDistance / element.clientHeight);
            scope.panUp(2 * delta.y * targetDistance / element.clientHeight);
        } else if (scope.object.top !== undefined) {
            scope.panLeft(delta.x * (scope.object.right - scope.object.left) / element.clientWidth);
            scope.panUp(delta.y * (scope.object.top - scope.object.bottom) / element.clientHeight);
        } else {
            console.warn('WARNING: OrbitControls.js encountered an unknown camera type - pan disabled.');
        }
    };
    this.dollyIn = function(dollyScale) {
        if (dollyScale === undefined) {
            dollyScale = getZoomScale();
        }
        scale /= dollyScale;
    };
    this.dollyOut = function(dollyScale) {
        if (dollyScale === undefined) {
            dollyScale = getZoomScale();
        }
        scale *= dollyScale;
    };
    this.update = function() {
        var position = this.object.position;
        var offset = position.clone().sub(this.target);
        var theta = Math.atan2(offset.x, offset.z);
        var phi = Math.atan2(Math.sqrt(offset.x * offset.x + offset.z * offset.z), offset.y);
        if (this.autoRotate) {
            this.rotateLeft(getAutoRotationAngle());
        }
        theta += thetaDelta;
        phi += phiDelta;
        phi = Math.max(this.minPolarAngle, Math.min(this.maxPolarAngle, phi));
        phi = Math.max(EPS, Math.min(Math.PI - EPS, phi));
        var radius = offset.length() * scale;
        radius = Math.max(this.minDistance, Math.min(this.maxDistance, radius));
        this.target.add(pan);
        offset.x = radius * Math.sin(phi) * Math.sin(theta);
        offset.y = radius * Math.cos(phi);
        offset.z = radius * Math.sin(phi) * Math.cos(theta);
        position.copy(this.target).add(offset);
        this.object.lookAt(this.target);
        thetaDelta = 0;
        phiDelta = 0;
        scale = 1;
        pan.set(0, 0, 0);
        if (lastPosition.distanceTo(this.object.position) > 0) {
            this.dispatchEvent(changeEvent);
            lastPosition.copy(this.object.position);
        }
    };

    function getAutoRotationAngle() {
        return 2 * Math.PI / 60 / 60 * scope.autoRotateSpeed;
    }

    function getZoomScale() {
        return Math.pow(0.95, scope.zoomSpeed);
    }

    function onMouseDown(event) {
        if (scope.enabled === false) {
            return;
        }
        event.preventDefault();
        if (event.button === 0) {
            if (scope.noRotate === true) {
                return;
            }
            state = STATE.ROTATE;
            rotateStart.set(event.clientX, event.clientY);
        } else if (event.button === 1) {
            if (scope.noZoom === true) {
                return;
            }
            state = STATE.DOLLY;
            dollyStart.set(event.clientX, event.clientY);
        } else if (event.button === 2) {
            if (scope.noPan === true) {
                return;
            }
            state = STATE.PAN;
            panStart.set(event.clientX, event.clientY);
        }
        scope.domElement.addEventListener('mousemove', onMouseMove, false);
        scope.domElement.addEventListener('mouseup', onMouseUp, false);
    }

    function onMouseMove(event) {
        if (scope.enabled === false) return;
        event.preventDefault();
        var element = scope.domElement === document ? scope.domElement.body : scope.domElement;
        if (state === STATE.ROTATE) {
            if (scope.noRotate === true) return;
            rotateEnd.set(event.clientX, event.clientY);
            rotateDelta.subVectors(rotateEnd, rotateStart);
            scope.rotateLeft(2 * Math.PI * rotateDelta.x / element.clientWidth * scope.rotateSpeed);
            scope.rotateUp(2 * Math.PI * rotateDelta.y / element.clientHeight * scope.rotateSpeed);
            rotateStart.copy(rotateEnd);
        } else if (state === STATE.DOLLY) {
            if (scope.noZoom === true) return;
            dollyEnd.set(event.clientX, event.clientY);
            dollyDelta.subVectors(dollyEnd, dollyStart);
            if (dollyDelta.y > 0) {
                scope.dollyIn();
            } else {
                scope.dollyOut();
            }
            dollyStart.copy(dollyEnd);
        } else if (state === STATE.PAN) {
            if (scope.noPan === true) return;
            panEnd.set(event.clientX, event.clientY);
            panDelta.subVectors(panEnd, panStart);
            scope.pan(panDelta);
            panStart.copy(panEnd);
        }
        scope.update();
    }

    function onMouseUp() {
        if (scope.enabled === false) return;
        scope.domElement.removeEventListener('mousemove', onMouseMove, false);
        scope.domElement.removeEventListener('mouseup', onMouseUp, false);
        state = STATE.NONE;
    }

    function onMouseWheel(event) {
        if (scope.enabled === false || scope.noZoom === true) return;
        var delta = 0;
        if (event.wheelDelta) {
            delta = event.wheelDelta;
        } else if (event.detail) {
            delta = -event.detail;
        }
        if (delta > 0) {
            scope.dollyOut();
        } else {
            scope.dollyIn();
        }
    }

    function onKeyDown(event) {
        if (scope.enabled === false) {
            return;
        }
        if (scope.noKeys === true) {
            return;
        }
        if (scope.noPan === true) {
            return;
        }
        var needUpdate = false;
        switch (event.keyCode) {
            case scope.keys.UP:
                scope.pan(new THREE.Vector2(0, scope.keyPanSpeed));
                needUpdate = true;
                break;
            case scope.keys.BOTTOM:
                scope.pan(new THREE.Vector2(0, -scope.keyPanSpeed));
                needUpdate = true;
                break;
            case scope.keys.LEFT:
                scope.pan(new THREE.Vector2(scope.keyPanSpeed, 0));
                needUpdate = true;
                break;
            case scope.keys.RIGHT:
                scope.pan(new THREE.Vector2(-scope.keyPanSpeed, 0));
                needUpdate = true;
                break;
        }
        if (needUpdate) {
            scope.update();
        }
    }

    function touchstart(event) {
        if (scope.enabled === false) {
            return;
        }
        switch (event.touches.length) {
            case 1:
                if (scope.noRotate === true) {
                    return;
                }
                state = STATE.TOUCH_ROTATE;
                rotateStart.set(event.touches[0].pageX, event.touches[0].pageY);
                break;
            case 2:
                if (scope.noZoom === true) {
                    return;
                }
                state = STATE.TOUCH_DOLLY;
                var dx = event.touches[0].pageX - event.touches[1].pageX;
                var dy = event.touches[0].pageY - event.touches[1].pageY;
                var distance = Math.sqrt(dx * dx + dy * dy);
                dollyStart.set(0, distance);
                break;
            case 3:
                if (scope.noPan === true) {
                    return;
                }
                state = STATE.TOUCH_PAN;
                panStart.set(event.touches[0].pageX, event.touches[0].pageY);
                break;
            default:
                state = STATE.NONE;
        }
    }

    function touchmove(event) {
        if (scope.enabled === false) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        var element = scope.domElement === document ? scope.domElement.body : scope.domElement;
        switch (event.touches.length) {
            case 1:
                if (scope.noRotate === true) {
                    return;
                }
                if (state !== STATE.TOUCH_ROTATE) {
                    return;
                }
                rotateEnd.set(event.touches[0].pageX, event.touches[0].pageY);
                rotateDelta.subVectors(rotateEnd, rotateStart);
                scope.rotateLeft(2 * Math.PI * rotateDelta.x / element.clientWidth * scope.rotateSpeed);
                scope.rotateUp(2 * Math.PI * rotateDelta.y / element.clientHeight * scope.rotateSpeed);
                rotateStart.copy(rotateEnd);
                break;
            case 2:
                if (scope.noZoom === true) {
                    return;
                }
                if (state !== STATE.TOUCH_DOLLY) {
                    return;
                }
                var dx = event.touches[0].pageX - event.touches[1].pageX;
                var dy = event.touches[0].pageY - event.touches[1].pageY;
                var distance = Math.sqrt(dx * dx + dy * dy);
                dollyEnd.set(0, distance);
                dollyDelta.subVectors(dollyEnd, dollyStart);
                if (dollyDelta.y > 0) {
                    scope.dollyOut();
                } else {
                    scope.dollyIn();
                }
                dollyStart.copy(dollyEnd);
                break;
            case 3:
                if (scope.noPan === true) {
                    return;
                }
                if (state !== STATE.TOUCH_PAN) {
                    return;
                }
                panEnd.set(event.touches[0].pageX, event.touches[0].pageY);
                panDelta.subVectors(panEnd, panStart);
                scope.pan(panDelta);
                panStart.copy(panEnd);
                break;
            default:
                state = STATE.NONE;
        }
    }

    function touchend() {
        if (scope.enabled === false) {
            return;
        }
        state = STATE.NONE;
    }
    this.domElement.addEventListener('contextmenu', function(event) {
        event.preventDefault();
    }, false);
    this.domElement.addEventListener('mousedown', onMouseDown, false);
    this.domElement.addEventListener('mousewheel', onMouseWheel, false);
    this.domElement.addEventListener('DOMMouseScroll', onMouseWheel, false);
    this.domElement.addEventListener('keydown', onKeyDown, false);
    this.domElement.addEventListener('touchstart', touchstart, false);
    this.domElement.addEventListener('touchend', touchend, false);
    this.domElement.addEventListener('touchmove', touchmove, false);
};
THREE.OrbitControls.prototype = Object.create(THREE.EventDispatcher.prototype);
var Handlebars = (function() {
    var __module4__ = (function() {
        "use strict";
        var __exports__;

        function SafeString(string) {
            this.string = string;
        }
        SafeString.prototype.toString = function() {
            return "" + this.string;
        };
        __exports__ = SafeString;
        return __exports__;
    })();
    var __module3__ = (function(__dependency1__) {
        "use strict";
        var __exports__ = {};
        var SafeString = __dependency1__;
        var escape = {
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#x27;",
            "`": "&#x60;"
        };
        var badChars = /[&<>"'`]/g;
        var possible = /[&<>"'`]/;

        function escapeChar(chr) {
            return escape[chr] || "&amp;";
        }

        function extend(obj, value) {
            for (var key in value) {
                if (Object.prototype.hasOwnProperty.call(value, key)) {
                    obj[key] = value[key];
                }
            }
        }
        __exports__.extend = extend;
        var toString = Object.prototype.toString;
        __exports__.toString = toString;
        var isFunction = function(value) {
            return typeof value === 'function';
        };
        if (isFunction(/x/)) {
            isFunction = function(value) {
                return typeof value === 'function' && toString.call(value) === '[object Function]';
            };
        }
        var isFunction;
        __exports__.isFunction = isFunction;
        var isArray = Array.isArray || function(value) {
            return (value && typeof value === 'object') ? toString.call(value) === '[object Array]' : false;
        };
        __exports__.isArray = isArray;

        function escapeExpression(string) {
            if (string instanceof SafeString) {
                return string.toString();
            } else if (!string && string !== 0) {
                return "";
            }
            string = "" + string;
            if (!possible.test(string)) {
                return string;
            }
            return string.replace(badChars, escapeChar);
        }
        __exports__.escapeExpression = escapeExpression;

        function isEmpty(value) {
            if (!value && value !== 0) {
                return true;
            } else if (isArray(value) && value.length === 0) {
                return true;
            } else {
                return false;
            }
        }
        __exports__.isEmpty = isEmpty;
        return __exports__;
    })(__module4__);
    var __module5__ = (function() {
        "use strict";
        var __exports__;
        var errorProps = ['description', 'fileName', 'lineNumber', 'message', 'name', 'number', 'stack'];

        function Exception(message, node) {
            var line;
            if (node && node.firstLine) {
                line = node.firstLine;
                message += ' - ' + line + ':' + node.firstColumn;
            }
            var tmp = Error.prototype.constructor.call(this, message);
            for (var idx = 0; idx < errorProps.length; idx++) {
                this[errorProps[idx]] = tmp[errorProps[idx]];
            }
            if (line) {
                this.lineNumber = line;
                this.column = node.firstColumn;
            }
        }
        Exception.prototype = new Error();
        __exports__ = Exception;
        return __exports__;
    })();
    var __module2__ = (function(__dependency1__, __dependency2__) {
        "use strict";
        var __exports__ = {};
        var Utils = __dependency1__;
        var Exception = __dependency2__;
        var VERSION = "1.3.0";
        __exports__.VERSION = VERSION;
        var COMPILER_REVISION = 4;
        __exports__.COMPILER_REVISION = COMPILER_REVISION;
        var REVISION_CHANGES = {
            1: '<= 1.0.rc.2',
            2: '== 1.0.0-rc.3',
            3: '== 1.0.0-rc.4',
            4: '>= 1.0.0'
        };
        __exports__.REVISION_CHANGES = REVISION_CHANGES;
        var isArray = Utils.isArray,
            isFunction = Utils.isFunction,
            toString = Utils.toString,
            objectType = '[object Object]';

        function HandlebarsEnvironment(helpers, partials) {
            this.helpers = helpers || {};
            this.partials = partials || {};
            registerDefaultHelpers(this);
        }
        __exports__.HandlebarsEnvironment = HandlebarsEnvironment;
        HandlebarsEnvironment.prototype = {
            constructor: HandlebarsEnvironment,
            logger: logger,
            log: log,
            registerHelper: function(name, fn, inverse) {
                if (toString.call(name) === objectType) {
                    if (inverse || fn) {
                        throw new Exception('Arg not supported with multiple helpers');
                    }
                    Utils.extend(this.helpers, name);
                } else {
                    if (inverse) {
                        fn.not = inverse;
                    }
                    this.helpers[name] = fn;
                }
            },
            registerPartial: function(name, str) {
                if (toString.call(name) === objectType) {
                    Utils.extend(this.partials, name);
                } else {
                    this.partials[name] = str;
                }
            }
        };

        function registerDefaultHelpers(instance) {
            instance.registerHelper('helperMissing', function(arg) {
                if (arguments.length === 2) {
                    return undefined;
                } else {
                    throw new Exception("Missing helper: '" + arg + "'");
                }
            });
            instance.registerHelper('blockHelperMissing', function(context, options) {
                var inverse = options.inverse || function() {},
                    fn = options.fn;
                if (isFunction(context)) {
                    context = context.call(this);
                }
                if (context === true) {
                    return fn(this);
                } else if (context === false || context == null) {
                    return inverse(this);
                } else if (isArray(context)) {
                    if (context.length > 0) {
                        return instance.helpers.each(context, options);
                    } else {
                        return inverse(this);
                    }
                } else {
                    return fn(context);
                }
            });
            instance.registerHelper('each', function(context, options) {
                var fn = options.fn,
                    inverse = options.inverse;
                var i = 0,
                    ret = "",
                    data;
                if (isFunction(context)) {
                    context = context.call(this);
                }
                if (options.data) {
                    data = createFrame(options.data);
                }
                if (context && typeof context === 'object') {
                    if (isArray(context)) {
                        for (var j = context.length; i < j; i++) {
                            if (data) {
                                data.index = i;
                                data.first = (i === 0);
                                data.last = (i === (context.length - 1));
                            }
                            ret = ret + fn(context[i], {
                                data: data
                            });
                        }
                    } else {
                        for (var key in context) {
                            if (context.hasOwnProperty(key)) {
                                if (data) {
                                    data.key = key;
                                    data.index = i;
                                    data.first = (i === 0);
                                }
                                ret = ret + fn(context[key], {
                                    data: data
                                });
                                i++;
                            }
                        }
                    }
                }
                if (i === 0) {
                    ret = inverse(this);
                }
                return ret;
            });
            instance.registerHelper('if', function(conditional, options) {
                if (isFunction(conditional)) {
                    conditional = conditional.call(this);
                }
                if ((!options.hash.includeZero && !conditional) || Utils.isEmpty(conditional)) {
                    return options.inverse(this);
                } else {
                    return options.fn(this);
                }
            });
            instance.registerHelper('unless', function(conditional, options) {
                return instance.helpers['if'].call(this, conditional, {
                    fn: options.inverse,
                    inverse: options.fn,
                    hash: options.hash
                });
            });
            instance.registerHelper('with', function(context, options) {
                if (isFunction(context)) {
                    context = context.call(this);
                }
                if (!Utils.isEmpty(context)) return options.fn(context);
            });
            instance.registerHelper('log', function(context, options) {
                var level = options.data && options.data.level != null ? parseInt(options.data.level, 10) : 1;
                instance.log(level, context);
            });
        }
        var logger = {
            methodMap: {
                0: 'debug',
                1: 'info',
                2: 'warn',
                3: 'error'
            },
            DEBUG: 0,
            INFO: 1,
            WARN: 2,
            ERROR: 3,
            level: 3,
            log: function(level, obj) {
                if (logger.level <= level) {
                    var method = logger.methodMap[level];
                    if (typeof console !== 'undefined' && console[method]) {
                        console[method].call(console, obj);
                    }
                }
            }
        };
        __exports__.logger = logger;

        function log(level, obj) {
            logger.log(level, obj);
        }
        __exports__.log = log;
        var createFrame = function(object) {
            var obj = {};
            Utils.extend(obj, object);
            return obj;
        };
        __exports__.createFrame = createFrame;
        return __exports__;
    })(__module3__, __module5__);
    var __module6__ = (function(__dependency1__, __dependency2__, __dependency3__) {
        "use strict";
        var __exports__ = {};
        var Utils = __dependency1__;
        var Exception = __dependency2__;
        var COMPILER_REVISION = __dependency3__.COMPILER_REVISION;
        var REVISION_CHANGES = __dependency3__.REVISION_CHANGES;

        function checkRevision(compilerInfo) {
            var compilerRevision = compilerInfo && compilerInfo[0] || 1,
                currentRevision = COMPILER_REVISION;
            if (compilerRevision !== currentRevision) {
                if (compilerRevision < currentRevision) {
                    var runtimeVersions = REVISION_CHANGES[currentRevision],
                        compilerVersions = REVISION_CHANGES[compilerRevision];
                    throw new Exception("Template was precompiled with an older version of Handlebars than the current runtime. " + "Please update your precompiler to a newer version (" + runtimeVersions + ") or downgrade your runtime to an older version (" + compilerVersions + ").");
                } else {
                    throw new Exception("Template was precompiled with a newer version of Handlebars than the current runtime. " + "Please update your runtime to a newer version (" + compilerInfo[1] + ").");
                }
            }
        }
        __exports__.checkRevision = checkRevision;

        function template(templateSpec, env) {
            if (!env) {
                throw new Exception("No environment passed to template");
            }
            var invokePartialWrapper = function(partial, name, context, helpers, partials, data) {
                var result = env.VM.invokePartial.apply(this, arguments);
                if (result != null) {
                    return result;
                }
                if (env.compile) {
                    var options = {
                        helpers: helpers,
                        partials: partials,
                        data: data
                    };
                    partials[name] = env.compile(partial, {
                        data: data !== undefined
                    }, env);
                    return partials[name](context, options);
                } else {
                    throw new Exception("The partial " + name + " could not be compiled when running in runtime-only mode");
                }
            };
            var container = {
                escapeExpression: Utils.escapeExpression,
                invokePartial: invokePartialWrapper,
                programs: [],
                program: function(i, fn, data) {
                    var programWrapper = this.programs[i];
                    if (data) {
                        programWrapper = program(i, fn, data);
                    } else if (!programWrapper) {
                        programWrapper = this.programs[i] = program(i, fn);
                    }
                    return programWrapper;
                },
                merge: function(param, common) {
                    var ret = param || common;
                    if (param && common && (param !== common)) {
                        ret = {};
                        Utils.extend(ret, common);
                        Utils.extend(ret, param);
                    }
                    return ret;
                },
                programWithDepth: env.VM.programWithDepth,
                noop: env.VM.noop,
                compilerInfo: null
            };
            return function(context, options) {
                options = options || {};
                var namespace = options.partial ? options : env,
                    helpers, partials;
                if (!options.partial) {
                    helpers = options.helpers;
                    partials = options.partials;
                }
                var result = templateSpec.call(container, namespace, context, helpers, partials, options.data);
                if (!options.partial) {
                    env.VM.checkRevision(container.compilerInfo);
                }
                return result;
            };
        }
        __exports__.template = template;

        function programWithDepth(i, fn, data) {
            var args = Array.prototype.slice.call(arguments, 3);
            var prog = function(context, options) {
                options = options || {};
                return fn.apply(this, [context, options.data || data].concat(args));
            };
            prog.program = i;
            prog.depth = args.length;
            return prog;
        }
        __exports__.programWithDepth = programWithDepth;

        function program(i, fn, data) {
            var prog = function(context, options) {
                options = options || {};
                return fn(context, options.data || data);
            };
            prog.program = i;
            prog.depth = 0;
            return prog;
        }
        __exports__.program = program;

        function invokePartial(partial, name, context, helpers, partials, data) {
            var options = {
                partial: true,
                helpers: helpers,
                partials: partials,
                data: data
            };
            if (partial === undefined) {
                throw new Exception("The partial " + name + " could not be found");
            } else if (partial instanceof Function) {
                return partial(context, options);
            }
        }
        __exports__.invokePartial = invokePartial;

        function noop() {
            return "";
        }
        __exports__.noop = noop;
        return __exports__;
    })(__module3__, __module5__, __module2__);
    var __module1__ = (function(__dependency1__, __dependency2__, __dependency3__, __dependency4__, __dependency5__) {
        "use strict";
        var __exports__;
        var base = __dependency1__;
        var SafeString = __dependency2__;
        var Exception = __dependency3__;
        var Utils = __dependency4__;
        var runtime = __dependency5__;
        var create = function() {
            var hb = new base.HandlebarsEnvironment();
            Utils.extend(hb, base);
            hb.SafeString = SafeString;
            hb.Exception = Exception;
            hb.Utils = Utils;
            hb.VM = runtime;
            hb.template = function(spec) {
                return runtime.template(spec, hb);
            };
            return hb;
        };
        var Handlebars = create();
        Handlebars.create = create;
        __exports__ = Handlebars;
        return __exports__;
    })(__module2__, __module4__, __module5__, __module3__, __module6__);
    var __module7__ = (function(__dependency1__) {
        "use strict";
        var __exports__;
        var Exception = __dependency1__;

        function LocationInfo(locInfo) {
            locInfo = locInfo || {};
            this.firstLine = locInfo.first_line;
            this.firstColumn = locInfo.first_column;
            this.lastColumn = locInfo.last_column;
            this.lastLine = locInfo.last_line;
        }
        var AST = {
            ProgramNode: function(statements, inverseStrip, inverse, locInfo) {
                var inverseLocationInfo, firstInverseNode;
                if (arguments.length === 3) {
                    locInfo = inverse;
                    inverse = null;
                } else if (arguments.length === 2) {
                    locInfo = inverseStrip;
                    inverseStrip = null;
                }
                LocationInfo.call(this, locInfo);
                this.type = "program";
                this.statements = statements;
                this.strip = {};
                if (inverse) {
                    firstInverseNode = inverse[0];
                    if (firstInverseNode) {
                        inverseLocationInfo = {
                            first_line: firstInverseNode.firstLine,
                            last_line: firstInverseNode.lastLine,
                            last_column: firstInverseNode.lastColumn,
                            first_column: firstInverseNode.firstColumn
                        };
                        this.inverse = new AST.ProgramNode(inverse, inverseStrip, inverseLocationInfo);
                    } else {
                        this.inverse = new AST.ProgramNode(inverse, inverseStrip);
                    }
                    this.strip.right = inverseStrip.left;
                } else if (inverseStrip) {
                    this.strip.left = inverseStrip.right;
                }
            },
            MustacheNode: function(rawParams, hash, open, strip, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "mustache";
                this.strip = strip;
                if (open != null && open.charAt) {
                    var escapeFlag = open.charAt(3) || open.charAt(2);
                    this.escaped = escapeFlag !== '{' && escapeFlag !== '&';
                } else {
                    this.escaped = !!open;
                }
                if (rawParams instanceof AST.SexprNode) {
                    this.sexpr = rawParams;
                } else {
                    this.sexpr = new AST.SexprNode(rawParams, hash);
                }
                this.sexpr.isRoot = true;
                this.id = this.sexpr.id;
                this.params = this.sexpr.params;
                this.hash = this.sexpr.hash;
                this.eligibleHelper = this.sexpr.eligibleHelper;
                this.isHelper = this.sexpr.isHelper;
            },
            SexprNode: function(rawParams, hash, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "sexpr";
                this.hash = hash;
                var id = this.id = rawParams[0];
                var params = this.params = rawParams.slice(1);
                var eligibleHelper = this.eligibleHelper = id.isSimple;
                this.isHelper = eligibleHelper && (params.length || hash);
            },
            PartialNode: function(partialName, context, strip, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "partial";
                this.partialName = partialName;
                this.context = context;
                this.strip = strip;
            },
            BlockNode: function(mustache, program, inverse, close, locInfo) {
                LocationInfo.call(this, locInfo);
                if (mustache.sexpr.id.original !== close.path.original) {
                    throw new Exception(mustache.sexpr.id.original + " doesn't match " + close.path.original, this);
                }
                this.type = 'block';
                this.mustache = mustache;
                this.program = program;
                this.inverse = inverse;
                this.strip = {
                    left: mustache.strip.left,
                    right: close.strip.right
                };
                (program || inverse).strip.left = mustache.strip.right;
                (inverse || program).strip.right = close.strip.left;
                if (inverse && !program) {
                    this.isInverse = true;
                }
            },
            ContentNode: function(string, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "content";
                this.string = string;
            },
            HashNode: function(pairs, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "hash";
                this.pairs = pairs;
            },
            IdNode: function(parts, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "ID";
                var original = "",
                    dig = [],
                    depth = 0;
                for (var i = 0, l = parts.length; i < l; i++) {
                    var part = parts[i].part;
                    original += (parts[i].separator || '') + part;
                    if (part === ".." || part === "." || part === "this") {
                        if (dig.length > 0) {
                            throw new Exception("Invalid path: " + original, this);
                        } else if (part === "..") {
                            depth++;
                        } else {
                            this.isScoped = true;
                        }
                    } else {
                        dig.push(part);
                    }
                }
                this.original = original;
                this.parts = dig;
                this.string = dig.join('.');
                this.depth = depth;
                this.isSimple = parts.length === 1 && !this.isScoped && depth === 0;
                this.stringModeValue = this.string;
            },
            PartialNameNode: function(name, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "PARTIAL_NAME";
                this.name = name.original;
            },
            DataNode: function(id, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "DATA";
                this.id = id;
            },
            StringNode: function(string, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "STRING";
                this.original = this.string = this.stringModeValue = string;
            },
            IntegerNode: function(integer, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "INTEGER";
                this.original = this.integer = integer;
                this.stringModeValue = Number(integer);
            },
            BooleanNode: function(bool, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "BOOLEAN";
                this.bool = bool;
                this.stringModeValue = bool === "true";
            },
            CommentNode: function(comment, locInfo) {
                LocationInfo.call(this, locInfo);
                this.type = "comment";
                this.comment = comment;
            }
        };
        __exports__ = AST;
        return __exports__;
    })(__module5__);
    var __module9__ = (function() {
        "use strict";
        var __exports__;
        var handlebars = (function() {
            var parser = {
                trace: function trace() {},
                yy: {},
                symbols_: {
                    "error": 2,
                    "root": 3,
                    "statements": 4,
                    "EOF": 5,
                    "program": 6,
                    "simpleInverse": 7,
                    "statement": 8,
                    "openInverse": 9,
                    "closeBlock": 10,
                    "openBlock": 11,
                    "mustache": 12,
                    "partial": 13,
                    "CONTENT": 14,
                    "COMMENT": 15,
                    "OPEN_BLOCK": 16,
                    "sexpr": 17,
                    "CLOSE": 18,
                    "OPEN_INVERSE": 19,
                    "OPEN_ENDBLOCK": 20,
                    "path": 21,
                    "OPEN": 22,
                    "OPEN_UNESCAPED": 23,
                    "CLOSE_UNESCAPED": 24,
                    "OPEN_PARTIAL": 25,
                    "partialName": 26,
                    "partial_option0": 27,
                    "sexpr_repetition0": 28,
                    "sexpr_option0": 29,
                    "dataName": 30,
                    "param": 31,
                    "STRING": 32,
                    "INTEGER": 33,
                    "BOOLEAN": 34,
                    "OPEN_SEXPR": 35,
                    "CLOSE_SEXPR": 36,
                    "hash": 37,
                    "hash_repetition_plus0": 38,
                    "hashSegment": 39,
                    "ID": 40,
                    "EQUALS": 41,
                    "DATA": 42,
                    "pathSegments": 43,
                    "SEP": 44,
                    "$accept": 0,
                    "$end": 1
                },
                terminals_: {
                    2: "error",
                    5: "EOF",
                    14: "CONTENT",
                    15: "COMMENT",
                    16: "OPEN_BLOCK",
                    18: "CLOSE",
                    19: "OPEN_INVERSE",
                    20: "OPEN_ENDBLOCK",
                    22: "OPEN",
                    23: "OPEN_UNESCAPED",
                    24: "CLOSE_UNESCAPED",
                    25: "OPEN_PARTIAL",
                    32: "STRING",
                    33: "INTEGER",
                    34: "BOOLEAN",
                    35: "OPEN_SEXPR",
                    36: "CLOSE_SEXPR",
                    40: "ID",
                    41: "EQUALS",
                    42: "DATA",
                    44: "SEP"
                },
                productions_: [0, [3, 2],
                    [3, 1],
                    [6, 2],
                    [6, 3],
                    [6, 2],
                    [6, 1],
                    [6, 1],
                    [6, 0],
                    [4, 1],
                    [4, 2],
                    [8, 3],
                    [8, 3],
                    [8, 1],
                    [8, 1],
                    [8, 1],
                    [8, 1],
                    [11, 3],
                    [9, 3],
                    [10, 3],
                    [12, 3],
                    [12, 3],
                    [13, 4],
                    [7, 2],
                    [17, 3],
                    [17, 1],
                    [31, 1],
                    [31, 1],
                    [31, 1],
                    [31, 1],
                    [31, 1],
                    [31, 3],
                    [37, 1],
                    [39, 3],
                    [26, 1],
                    [26, 1],
                    [26, 1],
                    [30, 2],
                    [21, 1],
                    [43, 3],
                    [43, 1],
                    [27, 0],
                    [27, 1],
                    [28, 0],
                    [28, 2],
                    [29, 0],
                    [29, 1],
                    [38, 1],
                    [38, 2]
                ],
                performAction: function anonymous(yytext, yyleng, yylineno, yy, yystate, $$, _$) {
                    var $0 = $$.length - 1;
                    switch (yystate) {
                        case 1:
                            return new yy.ProgramNode($$[$0 - 1], this._$);
                            break;
                        case 2:
                            return new yy.ProgramNode([], this._$);
                            break;
                        case 3:
                            this.$ = new yy.ProgramNode([], $$[$0 - 1], $$[$0], this._$);
                            break;
                        case 4:
                            this.$ = new yy.ProgramNode($$[$0 - 2], $$[$0 - 1], $$[$0], this._$);
                            break;
                        case 5:
                            this.$ = new yy.ProgramNode($$[$0 - 1], $$[$0], [], this._$);
                            break;
                        case 6:
                            this.$ = new yy.ProgramNode($$[$0], this._$);
                            break;
                        case 7:
                            this.$ = new yy.ProgramNode([], this._$);
                            break;
                        case 8:
                            this.$ = new yy.ProgramNode([], this._$);
                            break;
                        case 9:
                            this.$ = [$$[$0]];
                            break;
                        case 10:
                            $$[$0 - 1].push($$[$0]);
                            this.$ = $$[$0 - 1];
                            break;
                        case 11:
                            this.$ = new yy.BlockNode($$[$0 - 2], $$[$0 - 1].inverse, $$[$0 - 1], $$[$0], this._$);
                            break;
                        case 12:
                            this.$ = new yy.BlockNode($$[$0 - 2], $$[$0 - 1], $$[$0 - 1].inverse, $$[$0], this._$);
                            break;
                        case 13:
                            this.$ = $$[$0];
                            break;
                        case 14:
                            this.$ = $$[$0];
                            break;
                        case 15:
                            this.$ = new yy.ContentNode($$[$0], this._$);
                            break;
                        case 16:
                            this.$ = new yy.CommentNode($$[$0], this._$);
                            break;
                        case 17:
                            this.$ = new yy.MustacheNode($$[$0 - 1], null, $$[$0 - 2], stripFlags($$[$0 - 2], $$[$0]), this._$);
                            break;
                        case 18:
                            this.$ = new yy.MustacheNode($$[$0 - 1], null, $$[$0 - 2], stripFlags($$[$0 - 2], $$[$0]), this._$);
                            break;
                        case 19:
                            this.$ = {
                                path: $$[$0 - 1],
                                strip: stripFlags($$[$0 - 2], $$[$0])
                            };
                            break;
                        case 20:
                            this.$ = new yy.MustacheNode($$[$0 - 1], null, $$[$0 - 2], stripFlags($$[$0 - 2], $$[$0]), this._$);
                            break;
                        case 21:
                            this.$ = new yy.MustacheNode($$[$0 - 1], null, $$[$0 - 2], stripFlags($$[$0 - 2], $$[$0]), this._$);
                            break;
                        case 22:
                            this.$ = new yy.PartialNode($$[$0 - 2], $$[$0 - 1], stripFlags($$[$0 - 3], $$[$0]), this._$);
                            break;
                        case 23:
                            this.$ = stripFlags($$[$0 - 1], $$[$0]);
                            break;
                        case 24:
                            this.$ = new yy.SexprNode([$$[$0 - 2]].concat($$[$0 - 1]), $$[$0], this._$);
                            break;
                        case 25:
                            this.$ = new yy.SexprNode([$$[$0]], null, this._$);
                            break;
                        case 26:
                            this.$ = $$[$0];
                            break;
                        case 27:
                            this.$ = new yy.StringNode($$[$0], this._$);
                            break;
                        case 28:
                            this.$ = new yy.IntegerNode($$[$0], this._$);
                            break;
                        case 29:
                            this.$ = new yy.BooleanNode($$[$0], this._$);
                            break;
                        case 30:
                            this.$ = $$[$0];
                            break;
                        case 31:
                            $$[$0 - 1].isHelper = true;
                            this.$ = $$[$0 - 1];
                            break;
                        case 32:
                            this.$ = new yy.HashNode($$[$0], this._$);
                            break;
                        case 33:
                            this.$ = [$$[$0 - 2], $$[$0]];
                            break;
                        case 34:
                            this.$ = new yy.PartialNameNode($$[$0], this._$);
                            break;
                        case 35:
                            this.$ = new yy.PartialNameNode(new yy.StringNode($$[$0], this._$), this._$);
                            break;
                        case 36:
                            this.$ = new yy.PartialNameNode(new yy.IntegerNode($$[$0], this._$));
                            break;
                        case 37:
                            this.$ = new yy.DataNode($$[$0], this._$);
                            break;
                        case 38:
                            this.$ = new yy.IdNode($$[$0], this._$);
                            break;
                        case 39:
                            $$[$0 - 2].push({
                                part: $$[$0],
                                separator: $$[$0 - 1]
                            });
                            this.$ = $$[$0 - 2];
                            break;
                        case 40:
                            this.$ = [{
                                part: $$[$0]
                            }];
                            break;
                        case 43:
                            this.$ = [];
                            break;
                        case 44:
                            $$[$0 - 1].push($$[$0]);
                            break;
                        case 47:
                            this.$ = [$$[$0]];
                            break;
                        case 48:
                            $$[$0 - 1].push($$[$0]);
                            break;
                    }
                },
                table: [{
                    3: 1,
                    4: 2,
                    5: [1, 3],
                    8: 4,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 11],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    1: [3]
                }, {
                    5: [1, 16],
                    8: 17,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 11],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    1: [2, 2]
                }, {
                    5: [2, 9],
                    14: [2, 9],
                    15: [2, 9],
                    16: [2, 9],
                    19: [2, 9],
                    20: [2, 9],
                    22: [2, 9],
                    23: [2, 9],
                    25: [2, 9]
                }, {
                    4: 20,
                    6: 18,
                    7: 19,
                    8: 4,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 21],
                    20: [2, 8],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    4: 20,
                    6: 22,
                    7: 19,
                    8: 4,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 21],
                    20: [2, 8],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    5: [2, 13],
                    14: [2, 13],
                    15: [2, 13],
                    16: [2, 13],
                    19: [2, 13],
                    20: [2, 13],
                    22: [2, 13],
                    23: [2, 13],
                    25: [2, 13]
                }, {
                    5: [2, 14],
                    14: [2, 14],
                    15: [2, 14],
                    16: [2, 14],
                    19: [2, 14],
                    20: [2, 14],
                    22: [2, 14],
                    23: [2, 14],
                    25: [2, 14]
                }, {
                    5: [2, 15],
                    14: [2, 15],
                    15: [2, 15],
                    16: [2, 15],
                    19: [2, 15],
                    20: [2, 15],
                    22: [2, 15],
                    23: [2, 15],
                    25: [2, 15]
                }, {
                    5: [2, 16],
                    14: [2, 16],
                    15: [2, 16],
                    16: [2, 16],
                    19: [2, 16],
                    20: [2, 16],
                    22: [2, 16],
                    23: [2, 16],
                    25: [2, 16]
                }, {
                    17: 23,
                    21: 24,
                    30: 25,
                    40: [1, 28],
                    42: [1, 27],
                    43: 26
                }, {
                    17: 29,
                    21: 24,
                    30: 25,
                    40: [1, 28],
                    42: [1, 27],
                    43: 26
                }, {
                    17: 30,
                    21: 24,
                    30: 25,
                    40: [1, 28],
                    42: [1, 27],
                    43: 26
                }, {
                    17: 31,
                    21: 24,
                    30: 25,
                    40: [1, 28],
                    42: [1, 27],
                    43: 26
                }, {
                    21: 33,
                    26: 32,
                    32: [1, 34],
                    33: [1, 35],
                    40: [1, 28],
                    43: 26
                }, {
                    1: [2, 1]
                }, {
                    5: [2, 10],
                    14: [2, 10],
                    15: [2, 10],
                    16: [2, 10],
                    19: [2, 10],
                    20: [2, 10],
                    22: [2, 10],
                    23: [2, 10],
                    25: [2, 10]
                }, {
                    10: 36,
                    20: [1, 37]
                }, {
                    4: 38,
                    8: 4,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 11],
                    20: [2, 7],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    7: 39,
                    8: 17,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 21],
                    20: [2, 6],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    17: 23,
                    18: [1, 40],
                    21: 24,
                    30: 25,
                    40: [1, 28],
                    42: [1, 27],
                    43: 26
                }, {
                    10: 41,
                    20: [1, 37]
                }, {
                    18: [1, 42]
                }, {
                    18: [2, 43],
                    24: [2, 43],
                    28: 43,
                    32: [2, 43],
                    33: [2, 43],
                    34: [2, 43],
                    35: [2, 43],
                    36: [2, 43],
                    40: [2, 43],
                    42: [2, 43]
                }, {
                    18: [2, 25],
                    24: [2, 25],
                    36: [2, 25]
                }, {
                    18: [2, 38],
                    24: [2, 38],
                    32: [2, 38],
                    33: [2, 38],
                    34: [2, 38],
                    35: [2, 38],
                    36: [2, 38],
                    40: [2, 38],
                    42: [2, 38],
                    44: [1, 44]
                }, {
                    21: 45,
                    40: [1, 28],
                    43: 26
                }, {
                    18: [2, 40],
                    24: [2, 40],
                    32: [2, 40],
                    33: [2, 40],
                    34: [2, 40],
                    35: [2, 40],
                    36: [2, 40],
                    40: [2, 40],
                    42: [2, 40],
                    44: [2, 40]
                }, {
                    18: [1, 46]
                }, {
                    18: [1, 47]
                }, {
                    24: [1, 48]
                }, {
                    18: [2, 41],
                    21: 50,
                    27: 49,
                    40: [1, 28],
                    43: 26
                }, {
                    18: [2, 34],
                    40: [2, 34]
                }, {
                    18: [2, 35],
                    40: [2, 35]
                }, {
                    18: [2, 36],
                    40: [2, 36]
                }, {
                    5: [2, 11],
                    14: [2, 11],
                    15: [2, 11],
                    16: [2, 11],
                    19: [2, 11],
                    20: [2, 11],
                    22: [2, 11],
                    23: [2, 11],
                    25: [2, 11]
                }, {
                    21: 51,
                    40: [1, 28],
                    43: 26
                }, {
                    8: 17,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 11],
                    20: [2, 3],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    4: 52,
                    8: 4,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 11],
                    20: [2, 5],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    14: [2, 23],
                    15: [2, 23],
                    16: [2, 23],
                    19: [2, 23],
                    20: [2, 23],
                    22: [2, 23],
                    23: [2, 23],
                    25: [2, 23]
                }, {
                    5: [2, 12],
                    14: [2, 12],
                    15: [2, 12],
                    16: [2, 12],
                    19: [2, 12],
                    20: [2, 12],
                    22: [2, 12],
                    23: [2, 12],
                    25: [2, 12]
                }, {
                    14: [2, 18],
                    15: [2, 18],
                    16: [2, 18],
                    19: [2, 18],
                    20: [2, 18],
                    22: [2, 18],
                    23: [2, 18],
                    25: [2, 18]
                }, {
                    18: [2, 45],
                    21: 56,
                    24: [2, 45],
                    29: 53,
                    30: 60,
                    31: 54,
                    32: [1, 57],
                    33: [1, 58],
                    34: [1, 59],
                    35: [1, 61],
                    36: [2, 45],
                    37: 55,
                    38: 62,
                    39: 63,
                    40: [1, 64],
                    42: [1, 27],
                    43: 26
                }, {
                    40: [1, 65]
                }, {
                    18: [2, 37],
                    24: [2, 37],
                    32: [2, 37],
                    33: [2, 37],
                    34: [2, 37],
                    35: [2, 37],
                    36: [2, 37],
                    40: [2, 37],
                    42: [2, 37]
                }, {
                    14: [2, 17],
                    15: [2, 17],
                    16: [2, 17],
                    19: [2, 17],
                    20: [2, 17],
                    22: [2, 17],
                    23: [2, 17],
                    25: [2, 17]
                }, {
                    5: [2, 20],
                    14: [2, 20],
                    15: [2, 20],
                    16: [2, 20],
                    19: [2, 20],
                    20: [2, 20],
                    22: [2, 20],
                    23: [2, 20],
                    25: [2, 20]
                }, {
                    5: [2, 21],
                    14: [2, 21],
                    15: [2, 21],
                    16: [2, 21],
                    19: [2, 21],
                    20: [2, 21],
                    22: [2, 21],
                    23: [2, 21],
                    25: [2, 21]
                }, {
                    18: [1, 66]
                }, {
                    18: [2, 42]
                }, {
                    18: [1, 67]
                }, {
                    8: 17,
                    9: 5,
                    11: 6,
                    12: 7,
                    13: 8,
                    14: [1, 9],
                    15: [1, 10],
                    16: [1, 12],
                    19: [1, 11],
                    20: [2, 4],
                    22: [1, 13],
                    23: [1, 14],
                    25: [1, 15]
                }, {
                    18: [2, 24],
                    24: [2, 24],
                    36: [2, 24]
                }, {
                    18: [2, 44],
                    24: [2, 44],
                    32: [2, 44],
                    33: [2, 44],
                    34: [2, 44],
                    35: [2, 44],
                    36: [2, 44],
                    40: [2, 44],
                    42: [2, 44]
                }, {
                    18: [2, 46],
                    24: [2, 46],
                    36: [2, 46]
                }, {
                    18: [2, 26],
                    24: [2, 26],
                    32: [2, 26],
                    33: [2, 26],
                    34: [2, 26],
                    35: [2, 26],
                    36: [2, 26],
                    40: [2, 26],
                    42: [2, 26]
                }, {
                    18: [2, 27],
                    24: [2, 27],
                    32: [2, 27],
                    33: [2, 27],
                    34: [2, 27],
                    35: [2, 27],
                    36: [2, 27],
                    40: [2, 27],
                    42: [2, 27]
                }, {
                    18: [2, 28],
                    24: [2, 28],
                    32: [2, 28],
                    33: [2, 28],
                    34: [2, 28],
                    35: [2, 28],
                    36: [2, 28],
                    40: [2, 28],
                    42: [2, 28]
                }, {
                    18: [2, 29],
                    24: [2, 29],
                    32: [2, 29],
                    33: [2, 29],
                    34: [2, 29],
                    35: [2, 29],
                    36: [2, 29],
                    40: [2, 29],
                    42: [2, 29]
                }, {
                    18: [2, 30],
                    24: [2, 30],
                    32: [2, 30],
                    33: [2, 30],
                    34: [2, 30],
                    35: [2, 30],
                    36: [2, 30],
                    40: [2, 30],
                    42: [2, 30]
                }, {
                    17: 68,
                    21: 24,
                    30: 25,
                    40: [1, 28],
                    42: [1, 27],
                    43: 26
                }, {
                    18: [2, 32],
                    24: [2, 32],
                    36: [2, 32],
                    39: 69,
                    40: [1, 70]
                }, {
                    18: [2, 47],
                    24: [2, 47],
                    36: [2, 47],
                    40: [2, 47]
                }, {
                    18: [2, 40],
                    24: [2, 40],
                    32: [2, 40],
                    33: [2, 40],
                    34: [2, 40],
                    35: [2, 40],
                    36: [2, 40],
                    40: [2, 40],
                    41: [1, 71],
                    42: [2, 40],
                    44: [2, 40]
                }, {
                    18: [2, 39],
                    24: [2, 39],
                    32: [2, 39],
                    33: [2, 39],
                    34: [2, 39],
                    35: [2, 39],
                    36: [2, 39],
                    40: [2, 39],
                    42: [2, 39],
                    44: [2, 39]
                }, {
                    5: [2, 22],
                    14: [2, 22],
                    15: [2, 22],
                    16: [2, 22],
                    19: [2, 22],
                    20: [2, 22],
                    22: [2, 22],
                    23: [2, 22],
                    25: [2, 22]
                }, {
                    5: [2, 19],
                    14: [2, 19],
                    15: [2, 19],
                    16: [2, 19],
                    19: [2, 19],
                    20: [2, 19],
                    22: [2, 19],
                    23: [2, 19],
                    25: [2, 19]
                }, {
                    36: [1, 72]
                }, {
                    18: [2, 48],
                    24: [2, 48],
                    36: [2, 48],
                    40: [2, 48]
                }, {
                    41: [1, 71]
                }, {
                    21: 56,
                    30: 60,
                    31: 73,
                    32: [1, 57],
                    33: [1, 58],
                    34: [1, 59],
                    35: [1, 61],
                    40: [1, 28],
                    42: [1, 27],
                    43: 26
                }, {
                    18: [2, 31],
                    24: [2, 31],
                    32: [2, 31],
                    33: [2, 31],
                    34: [2, 31],
                    35: [2, 31],
                    36: [2, 31],
                    40: [2, 31],
                    42: [2, 31]
                }, {
                    18: [2, 33],
                    24: [2, 33],
                    36: [2, 33],
                    40: [2, 33]
                }],
                defaultActions: {
                    3: [2, 2],
                    16: [2, 1],
                    50: [2, 42]
                },
                parseError: function parseError(str, hash) {
                    throw new Error(str);
                },
                parse: function parse(input) {
                    var self = this,
                        stack = [0],
                        vstack = [null],
                        lstack = [],
                        table = this.table,
                        yytext = "",
                        yylineno = 0,
                        yyleng = 0,
                        recovering = 0,
                        TERROR = 2,
                        EOF = 1;
                    this.lexer.setInput(input);
                    this.lexer.yy = this.yy;
                    this.yy.lexer = this.lexer;
                    this.yy.parser = this;
                    if (typeof this.lexer.yylloc == "undefined")
                        this.lexer.yylloc = {};
                    var yyloc = this.lexer.yylloc;
                    lstack.push(yyloc);
                    var ranges = this.lexer.options && this.lexer.options.ranges;
                    if (typeof this.yy.parseError === "function")
                        this.parseError = this.yy.parseError;

                    function popStack(n) {
                        stack.length = stack.length - 2 * n;
                        vstack.length = vstack.length - n;
                        lstack.length = lstack.length - n;
                    }

                    function lex() {
                        var token;
                        token = self.lexer.lex() || 1;
                        if (typeof token !== "number") {
                            token = self.symbols_[token] || token;
                        }
                        return token;
                    }
                    var symbol, preErrorSymbol, state, action, a, r, yyval = {},
                        p, len, newState, expected;
                    while (true) {
                        state = stack[stack.length - 1];
                        if (this.defaultActions[state]) {
                            action = this.defaultActions[state];
                        } else {
                            if (symbol === null || typeof symbol == "undefined") {
                                symbol = lex();
                            }
                            action = table[state] && table[state][symbol];
                        }
                        if (typeof action === "undefined" || !action.length || !action[0]) {
                            var errStr = "";
                            if (!recovering) {
                                expected = [];
                                for (p in table[state])
                                    if (this.terminals_[p] && p > 2) {
                                        expected.push("'" + this.terminals_[p] + "'");
                                    }
                                if (this.lexer.showPosition) {
                                    errStr = "Parse error on line " + (yylineno + 1) + ":\n" + this.lexer.showPosition() + "\nExpecting " + expected.join(", ") + ", got '" + (this.terminals_[symbol] || symbol) + "'";
                                } else {
                                    errStr = "Parse error on line " + (yylineno + 1) + ": Unexpected " + (symbol == 1 ? "end of input" : "'" + (this.terminals_[symbol] || symbol) + "'");
                                }
                                this.parseError(errStr, {
                                    text: this.lexer.match,
                                    token: this.terminals_[symbol] || symbol,
                                    line: this.lexer.yylineno,
                                    loc: yyloc,
                                    expected: expected
                                });
                            }
                        }
                        if (action[0] instanceof Array && action.length > 1) {
                            throw new Error("Parse Error: multiple actions possible at state: " + state + ", token: " + symbol);
                        }
                        switch (action[0]) {
                            case 1:
                                stack.push(symbol);
                                vstack.push(this.lexer.yytext);
                                lstack.push(this.lexer.yylloc);
                                stack.push(action[1]);
                                symbol = null;
                                if (!preErrorSymbol) {
                                    yyleng = this.lexer.yyleng;
                                    yytext = this.lexer.yytext;
                                    yylineno = this.lexer.yylineno;
                                    yyloc = this.lexer.yylloc;
                                    if (recovering > 0)
                                        recovering--;
                                } else {
                                    symbol = preErrorSymbol;
                                    preErrorSymbol = null;
                                }
                                break;
                            case 2:
                                len = this.productions_[action[1]][1];
                                yyval.$ = vstack[vstack.length - len];
                                yyval._$ = {
                                    first_line: lstack[lstack.length - (len || 1)].first_line,
                                    last_line: lstack[lstack.length - 1].last_line,
                                    first_column: lstack[lstack.length - (len || 1)].first_column,
                                    last_column: lstack[lstack.length - 1].last_column
                                };
                                if (ranges) {
                                    yyval._$.range = [lstack[lstack.length - (len || 1)].range[0], lstack[lstack.length - 1].range[1]];
                                }
                                r = this.performAction.call(yyval, yytext, yyleng, yylineno, this.yy, action[1], vstack, lstack);
                                if (typeof r !== "undefined") {
                                    return r;
                                }
                                if (len) {
                                    stack = stack.slice(0, -1 * len * 2);
                                    vstack = vstack.slice(0, -1 * len);
                                    lstack = lstack.slice(0, -1 * len);
                                }
                                stack.push(this.productions_[action[1]][0]);
                                vstack.push(yyval.$);
                                lstack.push(yyval._$);
                                newState = table[stack[stack.length - 2]][stack[stack.length - 1]];
                                stack.push(newState);
                                break;
                            case 3:
                                return true;
                        }
                    }
                    return true;
                }
            };

            function stripFlags(open, close) {
                return {
                    left: open.charAt(2) === '~',
                    right: close.charAt(0) === '~' || close.charAt(1) === '~'
                };
            }
            var lexer = (function() {
                var lexer = ({
                    EOF: 1,
                    parseError: function parseError(str, hash) {
                        if (this.yy.parser) {
                            this.yy.parser.parseError(str, hash);
                        } else {
                            throw new Error(str);
                        }
                    },
                    setInput: function(input) {
                        this._input = input;
                        this._more = this._less = this.done = false;
                        this.yylineno = this.yyleng = 0;
                        this.yytext = this.matched = this.match = '';
                        this.conditionStack = ['INITIAL'];
                        this.yylloc = {
                            first_line: 1,
                            first_column: 0,
                            last_line: 1,
                            last_column: 0
                        };
                        if (this.options.ranges) this.yylloc.range = [0, 0];
                        this.offset = 0;
                        return this;
                    },
                    input: function() {
                        var ch = this._input[0];
                        this.yytext += ch;
                        this.yyleng++;
                        this.offset++;
                        this.match += ch;
                        this.matched += ch;
                        var lines = ch.match(/(?:\r\n?|\n).*/g);
                        if (lines) {
                            this.yylineno++;
                            this.yylloc.last_line++;
                        } else {
                            this.yylloc.last_column++;
                        }
                        if (this.options.ranges) this.yylloc.range[1]++;
                        this._input = this._input.slice(1);
                        return ch;
                    },
                    unput: function(ch) {
                        var len = ch.length;
                        var lines = ch.split(/(?:\r\n?|\n)/g);
                        this._input = ch + this._input;
                        this.yytext = this.yytext.substr(0, this.yytext.length - len - 1);
                        this.offset -= len;
                        var oldLines = this.match.split(/(?:\r\n?|\n)/g);
                        this.match = this.match.substr(0, this.match.length - 1);
                        this.matched = this.matched.substr(0, this.matched.length - 1);
                        if (lines.length - 1) this.yylineno -= lines.length - 1;
                        var r = this.yylloc.range;
                        this.yylloc = {
                            first_line: this.yylloc.first_line,
                            last_line: this.yylineno + 1,
                            first_column: this.yylloc.first_column,
                            last_column: lines ? (lines.length === oldLines.length ? this.yylloc.first_column : 0) + oldLines[oldLines.length - lines.length].length - lines[0].length : this.yylloc.first_column - len
                        };
                        if (this.options.ranges) {
                            this.yylloc.range = [r[0], r[0] + this.yyleng - len];
                        }
                        return this;
                    },
                    more: function() {
                        this._more = true;
                        return this;
                    },
                    less: function(n) {
                        this.unput(this.match.slice(n));
                    },
                    pastInput: function() {
                        var past = this.matched.substr(0, this.matched.length - this.match.length);
                        return (past.length > 20 ? '...' : '') + past.substr(-20).replace(/\n/g, "");
                    },
                    upcomingInput: function() {
                        var next = this.match;
                        if (next.length < 20) {
                            next += this._input.substr(0, 20 - next.length);
                        }
                        return (next.substr(0, 20) + (next.length > 20 ? '...' : '')).replace(/\n/g, "");
                    },
                    showPosition: function() {
                        var pre = this.pastInput();
                        var c = new Array(pre.length + 1).join("-");
                        return pre + this.upcomingInput() + "\n" + c + "^";
                    },
                    next: function() {
                        if (this.done) {
                            return this.EOF;
                        }
                        if (!this._input) this.done = true;
                        var token, match, tempMatch, index, col, lines;
                        if (!this._more) {
                            this.yytext = '';
                            this.match = '';
                        }
                        var rules = this._currentRules();
                        for (var i = 0; i < rules.length; i++) {
                            tempMatch = this._input.match(this.rules[rules[i]]);
                            if (tempMatch && (!match || tempMatch[0].length > match[0].length)) {
                                match = tempMatch;
                                index = i;
                                if (!this.options.flex) break;
                            }
                        }
                        if (match) {
                            lines = match[0].match(/(?:\r\n?|\n).*/g);
                            if (lines) this.yylineno += lines.length;
                            this.yylloc = {
                                first_line: this.yylloc.last_line,
                                last_line: this.yylineno + 1,
                                first_column: this.yylloc.last_column,
                                last_column: lines ? lines[lines.length - 1].length - lines[lines.length - 1].match(/\r?\n?/)[0].length : this.yylloc.last_column + match[0].length
                            };
                            this.yytext += match[0];
                            this.match += match[0];
                            this.matches = match;
                            this.yyleng = this.yytext.length;
                            if (this.options.ranges) {
                                this.yylloc.range = [this.offset, this.offset += this.yyleng];
                            }
                            this._more = false;
                            this._input = this._input.slice(match[0].length);
                            this.matched += match[0];
                            token = this.performAction.call(this, this.yy, this, rules[index], this.conditionStack[this.conditionStack.length - 1]);
                            if (this.done && this._input) this.done = false;
                            if (token) return token;
                            else return;
                        }
                        if (this._input === "") {
                            return this.EOF;
                        } else {
                            return this.parseError('Lexical error on line ' + (this.yylineno + 1) + '. Unrecognized text.\n' + this.showPosition(), {
                                text: "",
                                token: null,
                                line: this.yylineno
                            });
                        }
                    },
                    lex: function lex() {
                        var r = this.next();
                        if (typeof r !== 'undefined') {
                            return r;
                        } else {
                            return this.lex();
                        }
                    },
                    begin: function begin(condition) {
                        this.conditionStack.push(condition);
                    },
                    popState: function popState() {
                        return this.conditionStack.pop();
                    },
                    _currentRules: function _currentRules() {
                        return this.conditions[this.conditionStack[this.conditionStack.length - 1]].rules;
                    },
                    topState: function() {
                        return this.conditionStack[this.conditionStack.length - 2];
                    },
                    pushState: function begin(condition) {
                        this.begin(condition);
                    }
                });
                lexer.options = {};
                lexer.performAction = function anonymous(yy, yy_, $avoiding_name_collisions, YY_START) {
                    function strip(start, end) {
                        return yy_.yytext = yy_.yytext.substr(start, yy_.yyleng - end);
                    }
                    var YYSTATE = YY_START
                    switch ($avoiding_name_collisions) {
                        case 0:
                            if (yy_.yytext.slice(-2) === "\\\\") {
                                strip(0, 1);
                                this.begin("mu");
                            } else if (yy_.yytext.slice(-1) === "\\") {
                                strip(0, 1);
                                this.begin("emu");
                            } else {
                                this.begin("mu");
                            }
                            if (yy_.yytext) return 14;
                            break;
                        case 1:
                            return 14;
                            break;
                        case 2:
                            this.popState();
                            return 14;
                            break;
                        case 3:
                            strip(0, 4);
                            this.popState();
                            return 15;
                            break;
                        case 4:
                            return 35;
                            break;
                        case 5:
                            return 36;
                            break;
                        case 6:
                            return 25;
                            break;
                        case 7:
                            return 16;
                            break;
                        case 8:
                            return 20;
                            break;
                        case 9:
                            return 19;
                            break;
                        case 10:
                            return 19;
                            break;
                        case 11:
                            return 23;
                            break;
                        case 12:
                            return 22;
                            break;
                        case 13:
                            this.popState();
                            this.begin('com');
                            break;
                        case 14:
                            strip(3, 5);
                            this.popState();
                            return 15;
                            break;
                        case 15:
                            return 22;
                            break;
                        case 16:
                            return 41;
                            break;
                        case 17:
                            return 40;
                            break;
                        case 18:
                            return 40;
                            break;
                        case 19:
                            return 44;
                            break;
                        case 20:
                            break;
                        case 21:
                            this.popState();
                            return 24;
                            break;
                        case 22:
                            this.popState();
                            return 18;
                            break;
                        case 23:
                            yy_.yytext = strip(1, 2).replace(/\\"/g, '"');
                            return 32;
                            break;
                        case 24:
                            yy_.yytext = strip(1, 2).replace(/\\'/g, "'");
                            return 32;
                            break;
                        case 25:
                            return 42;
                            break;
                        case 26:
                            return 34;
                            break;
                        case 27:
                            return 34;
                            break;
                        case 28:
                            return 33;
                            break;
                        case 29:
                            return 40;
                            break;
                        case 30:
                            yy_.yytext = strip(1, 2);
                            return 40;
                            break;
                        case 31:
                            return 'INVALID';
                            break;
                        case 32:
                            return 5;
                            break;
                    }
                };
                lexer.rules = [/^(?:[^\x00]*?(?=(\{\{)))/, /^(?:[^\x00]+)/, /^(?:[^\x00]{2,}?(?=(\{\{|\\\{\{|\\\\\{\{|$)))/, /^(?:[\s\S]*?--\}\})/, /^(?:\()/, /^(?:\))/, /^(?:\{\{(~)?>)/, /^(?:\{\{(~)?#)/, /^(?:\{\{(~)?\/)/, /^(?:\{\{(~)?\^)/, /^(?:\{\{(~)?\s*else\b)/, /^(?:\{\{(~)?\{)/, /^(?:\{\{(~)?&)/, /^(?:\{\{!--)/, /^(?:\{\{![\s\S]*?\}\})/, /^(?:\{\{(~)?)/, /^(?:=)/, /^(?:\.\.)/, /^(?:\.(?=([=~}\s\/.)])))/, /^(?:[\/.])/, /^(?:\s+)/, /^(?:\}(~)?\}\})/, /^(?:(~)?\}\})/, /^(?:"(\\["]|[^"])*")/, /^(?:'(\\[']|[^'])*')/, /^(?:@)/, /^(?:true(?=([~}\s)])))/, /^(?:false(?=([~}\s)])))/, /^(?:-?[0-9]+(?=([~}\s)])))/, /^(?:([^\s!"#%-,\.\/;->@\[-\^`\{-~]+(?=([=~}\s\/.)]))))/, /^(?:\[[^\]]*\])/, /^(?:.)/, /^(?:$)/];
                lexer.conditions = {
                    "mu": {
                        "rules": [4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32],
                        "inclusive": false
                    },
                    "emu": {
                        "rules": [2],
                        "inclusive": false
                    },
                    "com": {
                        "rules": [3],
                        "inclusive": false
                    },
                    "INITIAL": {
                        "rules": [0, 1, 32],
                        "inclusive": true
                    }
                };
                return lexer;
            })()
            parser.lexer = lexer;

            function Parser() {
                this.yy = {};
            }
            Parser.prototype = parser;
            parser.Parser = Parser;
            return new Parser;
        })();
        __exports__ = handlebars;
        return __exports__;
    })();
    var __module8__ = (function(__dependency1__, __dependency2__) {
        "use strict";
        var __exports__ = {};
        var parser = __dependency1__;
        var AST = __dependency2__;
        __exports__.parser = parser;

        function parse(input) {
            if (input.constructor === AST.ProgramNode) {
                return input;
            }
            parser.yy = AST;
            return parser.parse(input);
        }
        __exports__.parse = parse;
        return __exports__;
    })(__module9__, __module7__);
    var __module10__ = (function(__dependency1__) {
        "use strict";
        var __exports__ = {};
        var Exception = __dependency1__;

        function Compiler() {}
        __exports__.Compiler = Compiler;
        Compiler.prototype = {
            compiler: Compiler,
            disassemble: function() {
                var opcodes = this.opcodes,
                    opcode, out = [],
                    params, param;
                for (var i = 0, l = opcodes.length; i < l; i++) {
                    opcode = opcodes[i];
                    if (opcode.opcode === 'DECLARE') {
                        out.push("DECLARE " + opcode.name + "=" + opcode.value);
                    } else {
                        params = [];
                        for (var j = 0; j < opcode.args.length; j++) {
                            param = opcode.args[j];
                            if (typeof param === "string") {
                                param = "\"" + param.replace("\n", "\\n") + "\"";
                            }
                            params.push(param);
                        }
                        out.push(opcode.opcode + " " + params.join(" "));
                    }
                }
                return out.join("\n");
            },
            equals: function(other) {
                var len = this.opcodes.length;
                if (other.opcodes.length !== len) {
                    return false;
                }
                for (var i = 0; i < len; i++) {
                    var opcode = this.opcodes[i],
                        otherOpcode = other.opcodes[i];
                    if (opcode.opcode !== otherOpcode.opcode || opcode.args.length !== otherOpcode.args.length) {
                        return false;
                    }
                    for (var j = 0; j < opcode.args.length; j++) {
                        if (opcode.args[j] !== otherOpcode.args[j]) {
                            return false;
                        }
                    }
                }
                len = this.children.length;
                if (other.children.length !== len) {
                    return false;
                }
                for (i = 0; i < len; i++) {
                    if (!this.children[i].equals(other.children[i])) {
                        return false;
                    }
                }
                return true;
            },
            guid: 0,
            compile: function(program, options) {
                this.opcodes = [];
                this.children = [];
                this.depths = {
                    list: []
                };
                this.options = options;
                var knownHelpers = this.options.knownHelpers;
                this.options.knownHelpers = {
                    'helperMissing': true,
                    'blockHelperMissing': true,
                    'each': true,
                    'if': true,
                    'unless': true,
                    'with': true,
                    'log': true
                };
                if (knownHelpers) {
                    for (var name in knownHelpers) {
                        this.options.knownHelpers[name] = knownHelpers[name];
                    }
                }
                return this.accept(program);
            },
            accept: function(node) {
                var strip = node.strip || {},
                    ret;
                if (strip.left) {
                    this.opcode('strip');
                }
                ret = this[node.type](node);
                if (strip.right) {
                    this.opcode('strip');
                }
                return ret;
            },
            program: function(program) {
                var statements = program.statements;
                for (var i = 0, l = statements.length; i < l; i++) {
                    this.accept(statements[i]);
                }
                this.isSimple = l === 1;
                this.depths.list = this.depths.list.sort(function(a, b) {
                    return a - b;
                });
                return this;
            },
            compileProgram: function(program) {
                var result = new this.compiler().compile(program, this.options);
                var guid = this.guid++,
                    depth;
                this.usePartial = this.usePartial || result.usePartial;
                this.children[guid] = result;
                for (var i = 0, l = result.depths.list.length; i < l; i++) {
                    depth = result.depths.list[i];
                    if (depth < 2) {
                        continue;
                    } else {
                        this.addDepth(depth - 1);
                    }
                }
                return guid;
            },
            block: function(block) {
                var mustache = block.mustache,
                    program = block.program,
                    inverse = block.inverse;
                if (program) {
                    program = this.compileProgram(program);
                }
                if (inverse) {
                    inverse = this.compileProgram(inverse);
                }
                var sexpr = mustache.sexpr;
                var type = this.classifySexpr(sexpr);
                if (type === "helper") {
                    this.helperSexpr(sexpr, program, inverse);
                } else if (type === "simple") {
                    this.simpleSexpr(sexpr);
                    this.opcode('pushProgram', program);
                    this.opcode('pushProgram', inverse);
                    this.opcode('emptyHash');
                    this.opcode('blockValue');
                } else {
                    this.ambiguousSexpr(sexpr, program, inverse);
                    this.opcode('pushProgram', program);
                    this.opcode('pushProgram', inverse);
                    this.opcode('emptyHash');
                    this.opcode('ambiguousBlockValue');
                }
                this.opcode('append');
            },
            hash: function(hash) {
                var pairs = hash.pairs,
                    pair, val;
                this.opcode('pushHash');
                for (var i = 0, l = pairs.length; i < l; i++) {
                    pair = pairs[i];
                    val = pair[1];
                    if (this.options.stringParams) {
                        if (val.depth) {
                            this.addDepth(val.depth);
                        }
                        this.opcode('getContext', val.depth || 0);
                        this.opcode('pushStringParam', val.stringModeValue, val.type);
                        if (val.type === 'sexpr') {
                            this.sexpr(val);
                        }
                    } else {
                        this.accept(val);
                    }
                    this.opcode('assignToHash', pair[0]);
                }
                this.opcode('popHash');
            },
            partial: function(partial) {
                var partialName = partial.partialName;
                this.usePartial = true;
                if (partial.context) {
                    this.ID(partial.context);
                } else {
                    this.opcode('push', 'depth0');
                }
                this.opcode('invokePartial', partialName.name);
                this.opcode('append');
            },
            content: function(content) {
                this.opcode('appendContent', content.string);
            },
            mustache: function(mustache) {
                this.sexpr(mustache.sexpr);
                if (mustache.escaped && !this.options.noEscape) {
                    this.opcode('appendEscaped');
                } else {
                    this.opcode('append');
                }
            },
            ambiguousSexpr: function(sexpr, program, inverse) {
                var id = sexpr.id,
                    name = id.parts[0],
                    isBlock = program != null || inverse != null;
                this.opcode('getContext', id.depth);
                this.opcode('pushProgram', program);
                this.opcode('pushProgram', inverse);
                this.opcode('invokeAmbiguous', name, isBlock);
            },
            simpleSexpr: function(sexpr) {
                var id = sexpr.id;
                if (id.type === 'DATA') {
                    this.DATA(id);
                } else if (id.parts.length) {
                    this.ID(id);
                } else {
                    this.addDepth(id.depth);
                    this.opcode('getContext', id.depth);
                    this.opcode('pushContext');
                }
                this.opcode('resolvePossibleLambda');
            },
            helperSexpr: function(sexpr, program, inverse) {
                var params = this.setupFullMustacheParams(sexpr, program, inverse),
                    name = sexpr.id.parts[0];
                if (this.options.knownHelpers[name]) {
                    this.opcode('invokeKnownHelper', params.length, name);
                } else if (this.options.knownHelpersOnly) {
                    throw new Exception("You specified knownHelpersOnly, but used the unknown helper " + name, sexpr);
                } else {
                    this.opcode('invokeHelper', params.length, name, sexpr.isRoot);
                }
            },
            sexpr: function(sexpr) {
                var type = this.classifySexpr(sexpr);
                if (type === "simple") {
                    this.simpleSexpr(sexpr);
                } else if (type === "helper") {
                    this.helperSexpr(sexpr);
                } else {
                    this.ambiguousSexpr(sexpr);
                }
            },
            ID: function(id) {
                this.addDepth(id.depth);
                this.opcode('getContext', id.depth);
                var name = id.parts[0];
                if (!name) {
                    this.opcode('pushContext');
                } else {
                    this.opcode('lookupOnContext', id.parts[0]);
                }
                for (var i = 1, l = id.parts.length; i < l; i++) {
                    this.opcode('lookup', id.parts[i]);
                }
            },
            DATA: function(data) {
                this.options.data = true;
                if (data.id.isScoped || data.id.depth) {
                    throw new Exception('Scoped data references are not supported: ' + data.original, data);
                }
                this.opcode('lookupData');
                var parts = data.id.parts;
                for (var i = 0, l = parts.length; i < l; i++) {
                    this.opcode('lookup', parts[i]);
                }
            },
            STRING: function(string) {
                this.opcode('pushString', string.string);
            },
            INTEGER: function(integer) {
                this.opcode('pushLiteral', integer.integer);
            },
            BOOLEAN: function(bool) {
                this.opcode('pushLiteral', bool.bool);
            },
            comment: function() {},
            opcode: function(name) {
                this.opcodes.push({
                    opcode: name,
                    args: [].slice.call(arguments, 1)
                });
            },
            declare: function(name, value) {
                this.opcodes.push({
                    opcode: 'DECLARE',
                    name: name,
                    value: value
                });
            },
            addDepth: function(depth) {
                if (depth === 0) {
                    return;
                }
                if (!this.depths[depth]) {
                    this.depths[depth] = true;
                    this.depths.list.push(depth);
                }
            },
            classifySexpr: function(sexpr) {
                var isHelper = sexpr.isHelper;
                var isEligible = sexpr.eligibleHelper;
                var options = this.options;
                if (isEligible && !isHelper) {
                    var name = sexpr.id.parts[0];
                    if (options.knownHelpers[name]) {
                        isHelper = true;
                    } else if (options.knownHelpersOnly) {
                        isEligible = false;
                    }
                }
                if (isHelper) {
                    return "helper";
                } else if (isEligible) {
                    return "ambiguous";
                } else {
                    return "simple";
                }
            },
            pushParams: function(params) {
                var i = params.length,
                    param;
                while (i--) {
                    param = params[i];
                    if (this.options.stringParams) {
                        if (param.depth) {
                            this.addDepth(param.depth);
                        }
                        this.opcode('getContext', param.depth || 0);
                        this.opcode('pushStringParam', param.stringModeValue, param.type);
                        if (param.type === 'sexpr') {
                            this.sexpr(param);
                        }
                    } else {
                        this[param.type](param);
                    }
                }
            },
            setupFullMustacheParams: function(sexpr, program, inverse) {
                var params = sexpr.params;
                this.pushParams(params);
                this.opcode('pushProgram', program);
                this.opcode('pushProgram', inverse);
                if (sexpr.hash) {
                    this.hash(sexpr.hash);
                } else {
                    this.opcode('emptyHash');
                }
                return params;
            }
        };

        function precompile(input, options, env) {
            if (input == null || (typeof input !== 'string' && input.constructor !== env.AST.ProgramNode)) {
                throw new Exception("You must pass a string or Handlebars AST to Handlebars.precompile. You passed " + input);
            }
            options = options || {};
            if (!('data' in options)) {
                options.data = true;
            }
            var ast = env.parse(input);
            var environment = new env.Compiler().compile(ast, options);
            return new env.JavaScriptCompiler().compile(environment, options);
        }
        __exports__.precompile = precompile;

        function compile(input, options, env) {
            if (input == null || (typeof input !== 'string' && input.constructor !== env.AST.ProgramNode)) {
                throw new Exception("You must pass a string or Handlebars AST to Handlebars.compile. You passed " + input);
            }
            options = options || {};
            if (!('data' in options)) {
                options.data = true;
            }
            var compiled;

            function compileInput() {
                var ast = env.parse(input);
                var environment = new env.Compiler().compile(ast, options);
                var templateSpec = new env.JavaScriptCompiler().compile(environment, options, undefined, true);
                return env.template(templateSpec);
            }
            return function(context, options) {
                if (!compiled) {
                    compiled = compileInput();
                }
                return compiled.call(this, context, options);
            };
        }
        __exports__.compile = compile;
        return __exports__;
    })(__module5__);
    var __module11__ = (function(__dependency1__, __dependency2__) {
        "use strict";
        var __exports__;
        var COMPILER_REVISION = __dependency1__.COMPILER_REVISION;
        var REVISION_CHANGES = __dependency1__.REVISION_CHANGES;
        var log = __dependency1__.log;
        var Exception = __dependency2__;

        function Literal(value) {
            this.value = value;
        }

        function JavaScriptCompiler() {}
        JavaScriptCompiler.prototype = {
            nameLookup: function(parent, name) {
                var wrap, ret;
                if (parent.indexOf('depth') === 0) {
                    wrap = true;
                }
                if (/^[0-9]+$/.test(name)) {
                    ret = parent + "[" + name + "]";
                } else if (JavaScriptCompiler.isValidJavaScriptVariableName(name)) {
                    ret = parent + "." + name;
                } else {
                    ret = parent + "['" + name + "']";
                }
                if (wrap) {
                    return '(' + parent + ' && ' + ret + ')';
                } else {
                    return ret;
                }
            },
            compilerInfo: function() {
                var revision = COMPILER_REVISION,
                    versions = REVISION_CHANGES[revision];
                return "this.compilerInfo = [" + revision + ",'" + versions + "'];\n";
            },
            appendToBuffer: function(string) {
                if (this.environment.isSimple) {
                    return "return " + string + ";";
                } else {
                    return {
                        appendToBuffer: true,
                        content: string,
                        toString: function() {
                            return "buffer += " + string + ";";
                        }
                    };
                }
            },
            initializeBuffer: function() {
                return this.quotedString("");
            },
            namespace: "Handlebars",
            compile: function(environment, options, context, asObject) {
                this.environment = environment;
                this.options = options || {};
                log('debug', this.environment.disassemble() + "\n\n");
                this.name = this.environment.name;
                this.isChild = !!context;
                this.context = context || {
                    programs: [],
                    environments: [],
                    aliases: {}
                };
                this.preamble();
                this.stackSlot = 0;
                this.stackVars = [];
                this.registers = {
                    list: []
                };
                this.hashes = [];
                this.compileStack = [];
                this.inlineStack = [];
                this.compileChildren(environment, options);
                var opcodes = environment.opcodes,
                    opcode;
                this.i = 0;
                for (var l = opcodes.length; this.i < l; this.i++) {
                    opcode = opcodes[this.i];
                    if (opcode.opcode === 'DECLARE') {
                        this[opcode.name] = opcode.value;
                    } else {
                        this[opcode.opcode].apply(this, opcode.args);
                    }
                    if (opcode.opcode !== this.stripNext) {
                        this.stripNext = false;
                    }
                }
                this.pushSource('');
                if (this.stackSlot || this.inlineStack.length || this.compileStack.length) {
                    throw new Exception('Compile completed with content left on stack');
                }
                return this.createFunctionContext(asObject);
            },
            preamble: function() {
                var out = [];
                if (!this.isChild) {
                    var namespace = this.namespace;
                    var copies = "helpers = this.merge(helpers, " + namespace + ".helpers);";
                    if (this.environment.usePartial) {
                        copies = copies + " partials = this.merge(partials, " + namespace + ".partials);";
                    }
                    if (this.options.data) {
                        copies = copies + " data = data || {};";
                    }
                    out.push(copies);
                } else {
                    out.push('');
                }
                if (!this.environment.isSimple) {
                    out.push(", buffer = " + this.initializeBuffer());
                } else {
                    out.push("");
                }
                this.lastContext = 0;
                this.source = out;
            },
            createFunctionContext: function(asObject) {
                var locals = this.stackVars.concat(this.registers.list);
                if (locals.length > 0) {
                    this.source[1] = this.source[1] + ", " + locals.join(", ");
                }
                if (!this.isChild) {
                    for (var alias in this.context.aliases) {
                        if (this.context.aliases.hasOwnProperty(alias)) {
                            this.source[1] = this.source[1] + ', ' + alias + '=' + this.context.aliases[alias];
                        }
                    }
                }
                if (this.source[1]) {
                    this.source[1] = "var " + this.source[1].substring(2) + ";";
                }
                if (!this.isChild) {
                    this.source[1] += '\n' + this.context.programs.join('\n') + '\n';
                }
                if (!this.environment.isSimple) {
                    this.pushSource("return buffer;");
                }
                var params = this.isChild ? ["depth0", "data"] : ["Handlebars", "depth0", "helpers", "partials", "data"];
                for (var i = 0, l = this.environment.depths.list.length; i < l; i++) {
                    params.push("depth" + this.environment.depths.list[i]);
                }
                var source = this.mergeSource();
                if (!this.isChild) {
                    source = this.compilerInfo() + source;
                }
                if (asObject) {
                    params.push(source);
                    return Function.apply(this, params);
                } else {
                    var functionSource = 'function ' + (this.name || '') + '(' + params.join(',') + ') {\n  ' + source + '}';
                    log('debug', functionSource + "\n\n");
                    return functionSource;
                }
            },
            mergeSource: function() {
                var source = '',
                    buffer;
                for (var i = 0, len = this.source.length; i < len; i++) {
                    var line = this.source[i];
                    if (line.appendToBuffer) {
                        if (buffer) {
                            buffer = buffer + '\n    + ' + line.content;
                        } else {
                            buffer = line.content;
                        }
                    } else {
                        if (buffer) {
                            source += 'buffer += ' + buffer + ';\n  ';
                            buffer = undefined;
                        }
                        source += line + '\n  ';
                    }
                }
                return source;
            },
            blockValue: function() {
                this.context.aliases.blockHelperMissing = 'helpers.blockHelperMissing';
                var params = ["depth0"];
                this.setupParams(0, params);
                this.replaceStack(function(current) {
                    params.splice(1, 0, current);
                    return "blockHelperMissing.call(" + params.join(", ") + ")";
                });
            },
            ambiguousBlockValue: function() {
                this.context.aliases.blockHelperMissing = 'helpers.blockHelperMissing';
                var params = ["depth0"];
                this.setupParams(0, params);
                var current = this.topStack();
                params.splice(1, 0, current);
                this.pushSource("if (!" + this.lastHelper + ") { " + current + " = blockHelperMissing.call(" + params.join(", ") + "); }");
            },
            appendContent: function(content) {
                if (this.pendingContent) {
                    content = this.pendingContent + content;
                }
                if (this.stripNext) {
                    content = content.replace(/^\s+/, '');
                }
                this.pendingContent = content;
            },
            strip: function() {
                if (this.pendingContent) {
                    this.pendingContent = this.pendingContent.replace(/\s+$/, '');
                }
                this.stripNext = 'strip';
            },
            append: function() {
                this.flushInline();
                var local = this.popStack();
                this.pushSource("if(" + local + " || " + local + " === 0) { " + this.appendToBuffer(local) + " }");
                if (this.environment.isSimple) {
                    this.pushSource("else { " + this.appendToBuffer("''") + " }");
                }
            },
            appendEscaped: function() {
                this.context.aliases.escapeExpression = 'this.escapeExpression';
                this.pushSource(this.appendToBuffer("escapeExpression(" + this.popStack() + ")"));
            },
            getContext: function(depth) {
                if (this.lastContext !== depth) {
                    this.lastContext = depth;
                }
            },
            lookupOnContext: function(name) {
                this.push(this.nameLookup('depth' + this.lastContext, name, 'context'));
            },
            pushContext: function() {
                this.pushStackLiteral('depth' + this.lastContext);
            },
            resolvePossibleLambda: function() {
                this.context.aliases.functionType = '"function"';
                this.replaceStack(function(current) {
                    return "typeof " + current + " === functionType ? " + current + ".apply(depth0) : " + current;
                });
            },
            lookup: function(name) {
                this.replaceStack(function(current) {
                    return current + " == null || " + current + " === false ? " + current + " : " + this.nameLookup(current, name, 'context');
                });
            },
            lookupData: function() {
                this.pushStackLiteral('data');
            },
            pushStringParam: function(string, type) {
                this.pushStackLiteral('depth' + this.lastContext);
                this.pushString(type);
                if (type !== 'sexpr') {
                    if (typeof string === 'string') {
                        this.pushString(string);
                    } else {
                        this.pushStackLiteral(string);
                    }
                }
            },
            emptyHash: function() {
                this.pushStackLiteral('{}');
                if (this.options.stringParams) {
                    this.push('{}');
                    this.push('{}');
                }
            },
            pushHash: function() {
                if (this.hash) {
                    this.hashes.push(this.hash);
                }
                this.hash = {
                    values: [],
                    types: [],
                    contexts: []
                };
            },
            popHash: function() {
                var hash = this.hash;
                this.hash = this.hashes.pop();
                if (this.options.stringParams) {
                    this.push('{' + hash.contexts.join(',') + '}');
                    this.push('{' + hash.types.join(',') + '}');
                }
                this.push('{\n    ' + hash.values.join(',\n    ') + '\n  }');
            },
            pushString: function(string) {
                this.pushStackLiteral(this.quotedString(string));
            },
            push: function(expr) {
                this.inlineStack.push(expr);
                return expr;
            },
            pushLiteral: function(value) {
                this.pushStackLiteral(value);
            },
            pushProgram: function(guid) {
                if (guid != null) {
                    this.pushStackLiteral(this.programExpression(guid));
                } else {
                    this.pushStackLiteral(null);
                }
            },
            invokeHelper: function(paramSize, name, isRoot) {
                this.context.aliases.helperMissing = 'helpers.helperMissing';
                this.useRegister('helper');
                var helper = this.lastHelper = this.setupHelper(paramSize, name, true);
                var nonHelper = this.nameLookup('depth' + this.lastContext, name, 'context');
                var lookup = 'helper = ' + helper.name + ' || ' + nonHelper;
                if (helper.paramsInit) {
                    lookup += ',' + helper.paramsInit;
                }
                this.push('(' + lookup + ',helper ' + '? helper.call(' + helper.callParams + ') ' + ': helperMissing.call(' + helper.helperMissingParams + '))');
                if (!isRoot) {
                    this.flushInline();
                }
            },
            invokeKnownHelper: function(paramSize, name) {
                var helper = this.setupHelper(paramSize, name);
                this.push(helper.name + ".call(" + helper.callParams + ")");
            },
            invokeAmbiguous: function(name, helperCall) {
                this.context.aliases.functionType = '"function"';
                this.useRegister('helper');
                this.emptyHash();
                var helper = this.setupHelper(0, name, helperCall);
                var helperName = this.lastHelper = this.nameLookup('helpers', name, 'helper');
                var nonHelper = this.nameLookup('depth' + this.lastContext, name, 'context');
                var nextStack = this.nextStack();
                if (helper.paramsInit) {
                    this.pushSource(helper.paramsInit);
                }
                this.pushSource('if (helper = ' + helperName + ') { ' + nextStack + ' = helper.call(' + helper.callParams + '); }');
                this.pushSource('else { helper = ' + nonHelper + '; ' + nextStack + ' = typeof helper === functionType ? helper.call(' + helper.callParams + ') : helper; }');
            },
            invokePartial: function(name) {
                var params = [this.nameLookup('partials', name, 'partial'), "'" + name + "'", this.popStack(), "helpers", "partials"];
                if (this.options.data) {
                    params.push("data");
                }
                this.context.aliases.self = "this";
                this.push("self.invokePartial(" + params.join(", ") + ")");
            },
            assignToHash: function(key) {
                var value = this.popStack(),
                    context, type;
                if (this.options.stringParams) {
                    type = this.popStack();
                    context = this.popStack();
                }
                var hash = this.hash;
                if (context) {
                    hash.contexts.push("'" + key + "': " + context);
                }
                if (type) {
                    hash.types.push("'" + key + "': " + type);
                }
                hash.values.push("'" + key + "': (" + value + ")");
            },
            compiler: JavaScriptCompiler,
            compileChildren: function(environment, options) {
                var children = environment.children,
                    child, compiler;
                for (var i = 0, l = children.length; i < l; i++) {
                    child = children[i];
                    compiler = new this.compiler();
                    var index = this.matchExistingProgram(child);
                    if (index == null) {
                        this.context.programs.push('');
                        index = this.context.programs.length;
                        child.index = index;
                        child.name = 'program' + index;
                        this.context.programs[index] = compiler.compile(child, options, this.context);
                        this.context.environments[index] = child;
                    } else {
                        child.index = index;
                        child.name = 'program' + index;
                    }
                }
            },
            matchExistingProgram: function(child) {
                for (var i = 0, len = this.context.environments.length; i < len; i++) {
                    var environment = this.context.environments[i];
                    if (environment && environment.equals(child)) {
                        return i;
                    }
                }
            },
            programExpression: function(guid) {
                this.context.aliases.self = "this";
                if (guid == null) {
                    return "self.noop";
                }
                var child = this.environment.children[guid],
                    depths = child.depths.list,
                    depth;
                var programParams = [child.index, child.name, "data"];
                for (var i = 0, l = depths.length; i < l; i++) {
                    depth = depths[i];
                    if (depth === 1) {
                        programParams.push("depth0");
                    } else {
                        programParams.push("depth" + (depth - 1));
                    }
                }
                return (depths.length === 0 ? "self.program(" : "self.programWithDepth(") + programParams.join(", ") + ")";
            },
            register: function(name, val) {
                this.useRegister(name);
                this.pushSource(name + " = " + val + ";");
            },
            useRegister: function(name) {
                if (!this.registers[name]) {
                    this.registers[name] = true;
                    this.registers.list.push(name);
                }
            },
            pushStackLiteral: function(item) {
                return this.push(new Literal(item));
            },
            pushSource: function(source) {
                if (this.pendingContent) {
                    this.source.push(this.appendToBuffer(this.quotedString(this.pendingContent)));
                    this.pendingContent = undefined;
                }
                if (source) {
                    this.source.push(source);
                }
            },
            pushStack: function(item) {
                this.flushInline();
                var stack = this.incrStack();
                if (item) {
                    this.pushSource(stack + " = " + item + ";");
                }
                this.compileStack.push(stack);
                return stack;
            },
            replaceStack: function(callback) {
                var prefix = '',
                    inline = this.isInline(),
                    stack, createdStack, usedLiteral;
                if (inline) {
                    var top = this.popStack(true);
                    if (top instanceof Literal) {
                        stack = top.value;
                        usedLiteral = true;
                    } else {
                        createdStack = !this.stackSlot;
                        var name = !createdStack ? this.topStackName() : this.incrStack();
                        prefix = '(' + this.push(name) + ' = ' + top + '),';
                        stack = this.topStack();
                    }
                } else {
                    stack = this.topStack();
                }
                var item = callback.call(this, stack);
                if (inline) {
                    if (!usedLiteral) {
                        this.popStack();
                    }
                    if (createdStack) {
                        this.stackSlot--;
                    }
                    this.push('(' + prefix + item + ')');
                } else {
                    if (!/^stack/.test(stack)) {
                        stack = this.nextStack();
                    }
                    this.pushSource(stack + " = (" + prefix + item + ");");
                }
                return stack;
            },
            nextStack: function() {
                return this.pushStack();
            },
            incrStack: function() {
                this.stackSlot++;
                if (this.stackSlot > this.stackVars.length) {
                    this.stackVars.push("stack" + this.stackSlot);
                }
                return this.topStackName();
            },
            topStackName: function() {
                return "stack" + this.stackSlot;
            },
            flushInline: function() {
                var inlineStack = this.inlineStack;
                if (inlineStack.length) {
                    this.inlineStack = [];
                    for (var i = 0, len = inlineStack.length; i < len; i++) {
                        var entry = inlineStack[i];
                        if (entry instanceof Literal) {
                            this.compileStack.push(entry);
                        } else {
                            this.pushStack(entry);
                        }
                    }
                }
            },
            isInline: function() {
                return this.inlineStack.length;
            },
            popStack: function(wrapped) {
                var inline = this.isInline(),
                    item = (inline ? this.inlineStack : this.compileStack).pop();
                if (!wrapped && (item instanceof Literal)) {
                    return item.value;
                } else {
                    if (!inline) {
                        if (!this.stackSlot) {
                            throw new Exception('Invalid stack pop');
                        }
                        this.stackSlot--;
                    }
                    return item;
                }
            },
            topStack: function(wrapped) {
                var stack = (this.isInline() ? this.inlineStack : this.compileStack),
                    item = stack[stack.length - 1];
                if (!wrapped && (item instanceof Literal)) {
                    return item.value;
                } else {
                    return item;
                }
            },
            quotedString: function(str) {
                return '"' + str.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n').replace(/\r/g, '\\r').replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029') + '"';
            },
            setupHelper: function(paramSize, name, missingParams) {
                var params = [],
                    paramsInit = this.setupParams(paramSize, params, missingParams);
                var foundHelper = this.nameLookup('helpers', name, 'helper');
                return {
                    params: params,
                    paramsInit: paramsInit,
                    name: foundHelper,
                    callParams: ["depth0"].concat(params).join(", "),
                    helperMissingParams: missingParams && ["depth0", this.quotedString(name)].concat(params).join(", ")
                };
            },
            setupOptions: function(paramSize, params) {
                var options = [],
                    contexts = [],
                    types = [],
                    param, inverse, program;
                options.push("hash:" + this.popStack());
                if (this.options.stringParams) {
                    options.push("hashTypes:" + this.popStack());
                    options.push("hashContexts:" + this.popStack());
                }
                inverse = this.popStack();
                program = this.popStack();
                if (program || inverse) {
                    if (!program) {
                        this.context.aliases.self = "this";
                        program = "self.noop";
                    }
                    if (!inverse) {
                        this.context.aliases.self = "this";
                        inverse = "self.noop";
                    }
                    options.push("inverse:" + inverse);
                    options.push("fn:" + program);
                }
                for (var i = 0; i < paramSize; i++) {
                    param = this.popStack();
                    params.push(param);
                    if (this.options.stringParams) {
                        types.push(this.popStack());
                        contexts.push(this.popStack());
                    }
                }
                if (this.options.stringParams) {
                    options.push("contexts:[" + contexts.join(",") + "]");
                    options.push("types:[" + types.join(",") + "]");
                }
                if (this.options.data) {
                    options.push("data:data");
                }
                return options;
            },
            setupParams: function(paramSize, params, useRegister) {
                var options = '{' + this.setupOptions(paramSize, params).join(',') + '}';
                if (useRegister) {
                    this.useRegister('options');
                    params.push('options');
                    return 'options=' + options;
                } else {
                    params.push(options);
                    return '';
                }
            }
        };
        var reservedWords = ("break else new var" + " case finally return void" + " catch for switch while" + " continue function this with" + " default if throw" + " delete in try" + " do instanceof typeof" + " abstract enum int short" + " boolean export interface static" + " byte extends long super" + " char final native synchronized" + " class float package throws" + " const goto private transient" + " debugger implements protected volatile" + " double import public let yield").split(" ");
        var compilerWords = JavaScriptCompiler.RESERVED_WORDS = {};
        for (var i = 0, l = reservedWords.length; i < l; i++) {
            compilerWords[reservedWords[i]] = true;
        }
        JavaScriptCompiler.isValidJavaScriptVariableName = function(name) {
            if (!JavaScriptCompiler.RESERVED_WORDS[name] && /^[a-zA-Z_$][0-9a-zA-Z_$]*$/.test(name)) {
                return true;
            }
            return false;
        };
        __exports__ = JavaScriptCompiler;
        return __exports__;
    })(__module2__, __module5__);
    var __module0__ = (function(__dependency1__, __dependency2__, __dependency3__, __dependency4__, __dependency5__) {
        "use strict";
        var __exports__;
        var Handlebars = __dependency1__;
        var AST = __dependency2__;
        var Parser = __dependency3__.parser;
        var parse = __dependency3__.parse;
        var Compiler = __dependency4__.Compiler;
        var compile = __dependency4__.compile;
        var precompile = __dependency4__.precompile;
        var JavaScriptCompiler = __dependency5__;
        var _create = Handlebars.create;
        var create = function() {
            var hb = _create();
            hb.compile = function(input, options) {
                return compile(input, options, hb);
            };
            hb.precompile = function(input, options) {
                return precompile(input, options, hb);
            };
            hb.AST = AST;
            hb.Compiler = Compiler;
            hb.JavaScriptCompiler = JavaScriptCompiler;
            hb.Parser = Parser;
            hb.parse = parse;
            return hb;
        };
        Handlebars = create();
        Handlebars.create = create;
        __exports__ = Handlebars;
        return __exports__;
    })(__module1__, __module7__, __module8__, __module10__, __module11__);
    return __module0__;
})();

function Rainbow() {
    var gradients = null;
    var minNum = 0;
    var maxNum = 100;
    var colours = ['ff0000', 'ffff00', '00ff00', '0000ff'];
    setColours(colours);

    function setColours(spectrum) {
        if (spectrum.length < 2) {
            throw new Error('Rainbow must have two or more colours.');
        } else {
            var increment = (maxNum - minNum) / (spectrum.length - 1);
            var firstGradient = new ColourGradient();
            firstGradient.setGradient(spectrum[0], spectrum[1]);
            firstGradient.setNumberRange(minNum, minNum + increment);
            gradients = [firstGradient];
            for (var i = 1; i < spectrum.length - 1; i++) {
                var colourGradient = new ColourGradient();
                colourGradient.setGradient(spectrum[i], spectrum[i + 1]);
                colourGradient.setNumberRange(minNum + increment * i, minNum + increment * (i + 1));
                gradients[i] = colourGradient;
            }
            colours = spectrum;
            return this;
        }
    }
    this.setColors = this.setColours;
    this.setSpectrum = function() {
        setColours(arguments);
        return this;
    }
    this.setSpectrumByArray = function(array) {
        setColours(array);
        return this;
    }
    this.colourAt = function(number) {
        if (isNaN(number)) {
            throw new TypeError(number + ' is not a number');
        } else if (gradients.length === 1) {
            return gradients[0].colourAt(number);
        } else {
            var segment = (maxNum - minNum) / (gradients.length);
            var index = Math.min(Math.floor((Math.max(number, minNum) - minNum) / segment), gradients.length - 1);
            return gradients[index].colourAt(number);
        }
    }
    this.colorAt = this.colourAt;
    this.setNumberRange = function(minNumber, maxNumber) {
        if (maxNumber > minNumber) {
            minNum = minNumber;
            maxNum = maxNumber;
            setColours(colours);
        } else {
            throw new RangeError('maxNumber (' + maxNumber + ') is not greater than minNumber (' + minNumber + ')');
        }
        return this;
    }
}

function ColourGradient() {
    var startColour = 'ff0000';
    var endColour = '0000ff';
    var minNum = 0;
    var maxNum = 100;
    var formatHex = function(hex) {
        if (hex.length === 1) {
            return '0' + hex;
        } else {
            return hex;
        }
    }
    this.setGradient = function(colourStart, colourEnd) {
        startColour = getHexColour(colourStart);
        endColour = getHexColour(colourEnd);
    }
    this.setNumberRange = function(minNumber, maxNumber) {
        if (maxNumber > minNumber) {
            minNum = minNumber;
            maxNum = maxNumber;
        } else {
            throw new RangeError('maxNumber (' + maxNumber + ') is not greater than minNumber (' + minNumber + ')');
        }
    }
    this.colourAt = function(number) {
        return calcHex(number, startColour.substring(0, 2), endColour.substring(0, 2)) + calcHex(number, startColour.substring(2, 4), endColour.substring(2, 4)) + calcHex(number, startColour.substring(4, 6), endColour.substring(4, 6));
    }

    function calcHex(number, channelStart_Base16, channelEnd_Base16) {
        var num = number;
        if (num < minNum) {
            num = minNum;
        }
        if (num > maxNum) {
            num = maxNum;
        }
        var numRange = maxNum - minNum;
        var cStart_Base10 = parseInt(channelStart_Base16, 16);
        var cEnd_Base10 = parseInt(channelEnd_Base16, 16);
        var cPerUnit = (cEnd_Base10 - cStart_Base10) / numRange;
        var c_Base10 = Math.round(cPerUnit * (num - minNum) + cStart_Base10);
        return formatHex(c_Base10.toString(16));
    }

    function isHexColour(string) {
        var regex = /^#?[0-9a-fA-F]{6}$/i;
        return regex.test(string);
    }

    function getHexColour(string) {
        if (isHexColour(string)) {
            return string.substring(string.length - 6, string.length);
        } else {
            var colourNames = [
                ['red', 'ff0000'],
                ['lime', '00ff00'],
                ['blue', '0000ff'],
                ['yellow', 'ffff00'],
                ['orange', 'ff8000'],
                ['aqua', '00ffff'],
                ['fuchsia', 'ff00ff'],
                ['white', 'ffffff'],
                ['black', '000000'],
                ['gray', '808080'],
                ['grey', '808080'],
                ['silver', 'c0c0c0'],
                ['maroon', '800000'],
                ['olive', '808000'],
                ['green', '008000'],
                ['teal', '008080'],
                ['navy', '000080'],
                ['purple', '800080']
            ];
            for (var i = 0; i < colourNames.length; i++) {
                if (string.toLowerCase() === colourNames[i][0]) {
                    return colourNames[i][1];
                }
            }
            throw new Error(string + ' is not a valid colour.');
        }
    }
}
(function() {
    var Byte, Client, Frame, Stomp, __hasProp = {}.hasOwnProperty,
        __slice = [].slice;
    Byte = {
        LF: '\x0A',
        NULL: '\x00'
    };
    Frame = (function() {
        var unmarshallSingle;

        function Frame(command, headers, body) {
            this.command = command;
            this.headers = headers != null ? headers : {};
            this.body = body != null ? body : '';
        }
        Frame.prototype.toString = function() {
            var lines, name, value, _ref;
            lines = [this.command];
            _ref = this.headers;
            for (name in _ref) {
                if (!__hasProp.call(_ref, name)) continue;
                value = _ref[name];
                lines.push("" + name + ":" + value);
            }
            if (this.body) {
                lines.push("content-length:" + ('' + this.body).length);
            }
            lines.push(Byte.LF + this.body);
            return lines.join(Byte.LF);
        };
        unmarshallSingle = function(data) {
            var body, chr, command, divider, headerLines, headers, i, idx, len, line, start, trim, _i, _j, _len, _ref, _ref1;
            divider = data.search(RegExp("" + Byte.LF + Byte.LF));
            headerLines = data.substring(0, divider).split(Byte.LF);
            command = headerLines.shift();
            headers = {};
            trim = function(str) {
                return str.replace(/^\s+|\s+$/g, '');
            };
            _ref = headerLines.reverse();
            for (_i = 0, _len = _ref.length; _i < _len; _i++) {
                line = _ref[_i];
                idx = line.indexOf(':');
                headers[trim(line.substring(0, idx))] = trim(line.substring(idx + 1));
            }
            body = '';
            start = divider + 2;
            if (headers['content-length']) {
                len = parseInt(headers['content-length']);
                body = ('' + data).substring(start, start + len);
            } else {
                chr = null;
                for (i = _j = start, _ref1 = data.length; start <= _ref1 ? _j < _ref1 : _j > _ref1; i = start <= _ref1 ? ++_j : --_j) {
                    chr = data.charAt(i);
                    if (chr === Byte.NULL) {
                        break;
                    }
                    body += chr;
                }
            }
            return new Frame(command, headers, body);
        };
        Frame.unmarshall = function(datas) {
            var data;
            return (function() {
                var _i, _len, _ref, _results;
                _ref = datas.split(RegExp("" + Byte.NULL + Byte.LF + "*"));
                _results = [];
                for (_i = 0, _len = _ref.length; _i < _len; _i++) {
                    data = _ref[_i];
                    if ((data != null ? data.length : void 0) > 0) {
                        _results.push(unmarshallSingle(data));
                    }
                }
                return _results;
            })();
        };
        Frame.marshall = function(command, headers, body) {
            var frame;
            frame = new Frame(command, headers, body);
            return frame.toString() + Byte.NULL;
        };
        return Frame;
    })();
    Client = (function() {
        var now;

        function Client(ws) {
            this.ws = ws;
            this.ws.binaryType = "arraybuffer";
            this.counter = 0;
            this.connected = false;
            this.heartbeat = {
                outgoing: 10000,
                incoming: 10000
            };
            this.maxWebSocketFrameSize = 16 * 1024;
            this.subscriptions = {};
        }
        Client.prototype.debug = function(message) {
            var _ref;
            return typeof window !== "undefined" && window !== null ? (_ref = window.console) != null ? _ref.log(message) : void 0 : void 0;
        };
        now = function() {
            return Date.now || new Date().valueOf;
        };
        Client.prototype._transmit = function(command, headers, body) {
            var out;
            out = Frame.marshall(command, headers, body);
            if (typeof this.debug === "function") {
                this.debug(">>> " + out);
            }
            while (true) {
                if (out.length > this.maxWebSocketFrameSize) {
                    this.ws.send(out.substring(0, this.maxWebSocketFrameSize));
                    out = out.substring(this.maxWebSocketFrameSize);
                    if (typeof this.debug === "function") {
                        this.debug("remaining = " + out.length);
                    }
                } else {
                    return this.ws.send(out);
                }
            }
        };
        Client.prototype._setupHeartbeat = function(headers) {
            var serverIncoming, serverOutgoing, ttl, v, _ref, _ref1, _this = this;
            if ((_ref = headers.version) !== Stomp.VERSIONS.V1_1 && _ref !== Stomp.VERSIONS.V1_2) {
                return;
            }
            _ref1 = (function() {
                var _i, _len, _ref1, _results;
                _ref1 = headers['heart-beat'].split(",");
                _results = [];
                for (_i = 0, _len = _ref1.length; _i < _len; _i++) {
                    v = _ref1[_i];
                    _results.push(parseInt(v));
                }
                return _results;
            })(), serverOutgoing = _ref1[0], serverIncoming = _ref1[1];
            if (!(this.heartbeat.outgoing === 0 || serverIncoming === 0)) {
                ttl = Math.max(this.heartbeat.outgoing, serverIncoming);
                if (typeof this.debug === "function") {
                    this.debug("send PING every " + ttl + "ms");
                }
                this.pinger = Stomp.setInterval(ttl, function() {
                    _this.ws.send(Byte.LF);
                    return typeof _this.debug === "function" ? _this.debug(">>> PING") : void 0;
                });
            }
            if (!(this.heartbeat.incoming === 0 || serverOutgoing === 0)) {
                ttl = Math.max(this.heartbeat.incoming, serverOutgoing);
                if (typeof this.debug === "function") {
                    this.debug("check PONG every " + ttl + "ms");
                }
                return this.ponger = Stomp.setInterval(ttl, function() {
                    var delta;
                    delta = now() - _this.serverActivity;
                    if (delta > ttl * 2) {
                        if (typeof _this.debug === "function") {
                            _this.debug("did not receive server activity for the last " + delta + "ms");
                        }
console.log('websocket closed (heartbeat)');
                        return _this.ws.close();
                    }
                });
            }
        };
        Client.prototype._parseConnect = function() {
            var args, connectCallback, errorCallback, headers;
            args = 1 <= arguments.length ? __slice.call(arguments, 0) : [];
            headers = {};
            switch (args.length) {
                case 2:
                    headers = args[0], connectCallback = args[1];
                    break;
                case 3:
                    if (args[1] instanceof Function) {
                        headers = args[0], connectCallback = args[1], errorCallback = args[2];
                    } else {
                        headers.login = args[0], headers.passcode = args[1], connectCallback = args[2];
                    }
                    break;
                case 4:
                    headers.login = args[0], headers.passcode = args[1], connectCallback = args[2], errorCallback = args[3];
                    break;
                default:
                    headers.login = args[0], headers.passcode = args[1], connectCallback = args[2], errorCallback = args[3], headers.host = args[4];
            }
            return [headers, connectCallback, errorCallback];
        };
        Client.prototype.connect = function() {
            var args, errorCallback, headers, out, _this = this;
            args = 1 <= arguments.length ? __slice.call(arguments, 0) : [];
            out = this._parseConnect.apply(this, args);
            headers = out[0], this.connectCallback = out[1], errorCallback = out[2];
            if (typeof this.debug === "function") {
                this.debug("Opening Web Socket...");
            }
            this.ws.onmessage = function(evt) {
                var arr, c, client, data, frame, messageID, onreceive, subscription, _i, _len, _ref, _results;
                data = typeof ArrayBuffer !== 'undefined' && evt.data instanceof ArrayBuffer ? (arr = new Uint8Array(evt.data), typeof _this.debug === "function" ? _this.debug("--- got data length: " + arr.length) : void 0, ((function() {
                    var _i, _len, _results;
                    _results = [];
                    for (_i = 0, _len = arr.length; _i < _len; _i++) {
                        c = arr[_i];
                        _results.push(String.fromCharCode(c));
                    }
                    return _results;
                })()).join('')) : evt.data;
                _this.serverActivity = now();
                if (data === Byte.LF) {
                    if (typeof _this.debug === "function") {
                        _this.debug("<<< PONG");
                    }
                    return;
                }
                if (typeof _this.debug === "function") {
                    _this.debug("<<< " + data);
                }
                _ref = Frame.unmarshall(data);
                _results = [];
                for (_i = 0, _len = _ref.length; _i < _len; _i++) {
                    frame = _ref[_i];
                    switch (frame.command) {
                        case "CONNECTED":
                            if (typeof _this.debug === "function") {
                                _this.debug("connected to server " + frame.headers.server);
                            }
                            _this.connected = true;
                            _this._setupHeartbeat(frame.headers);
                            _results.push(typeof _this.connectCallback === "function" ? _this.connectCallback(frame) : void 0);
                            break;
                        case "MESSAGE":
                            subscription = frame.headers.subscription;
                            onreceive = _this.subscriptions[subscription] || _this.onreceive;
                            if (onreceive) {
                                client = _this;
                                messageID = frame.headers["message-id"];
                                frame.ack = function(headers) {
                                    if (headers == null) {
                                        headers = {};
                                    }
                                    return client.ack(messageID, subscription, headers);
                                };
                                frame.nack = function(headers) {
                                    if (headers == null) {
                                        headers = {};
                                    }
                                    return client.nack(messageID, subscription, headers);
                                };
                                _results.push(onreceive(frame));
                            } else {
                                _results.push(typeof _this.debug === "function" ? _this.debug("Unhandled received MESSAGE: " + frame) : void 0);
                            }
                            break;
                        case "RECEIPT":
                            _results.push(typeof _this.onreceipt === "function" ? _this.onreceipt(frame) : void 0);
                            break;
                        case "ERROR":
                            _results.push(typeof errorCallback === "function" ? errorCallback(frame) : void 0);
                            break;
                        default:
                            _results.push(typeof _this.debug === "function" ? _this.debug("Unhandled frame: " + frame) : void 0);
                    }
                }
                return _results;
            };
            this.ws.onclose = function() {
                var msg;
                msg = "Whoops! Lost connection to " + _this.ws.url;
                if (typeof _this.debug === "function") {
                    _this.debug(msg);
                }
                _this._cleanUp();
                return typeof errorCallback === "function" ? errorCallback(msg) : void 0;
            };
            return this.ws.onopen = function() {
                if (typeof _this.debug === "function") {
                    _this.debug('Web Socket Opened...');
                }
                headers["accept-version"] = Stomp.VERSIONS.supportedVersions();
                headers["heart-beat"] = [_this.heartbeat.outgoing, _this.heartbeat.incoming].join(',');
                return _this._transmit("CONNECT", headers);
            };
        };
        Client.prototype.disconnect = function(disconnectCallback) {
            this._transmit("DISCONNECT");
            this.ws.onclose = null;
            this.ws.close();
            this._cleanUp();
            return typeof disconnectCallback === "function" ? disconnectCallback() : void 0;
        };
        Client.prototype._cleanUp = function() {
            this.connected = false;
            if (this.pinger) {
                Stomp.clearInterval(this.pinger);
            }
            if (this.ponger) {
                return Stomp.clearInterval(this.ponger);
            }
        };
        Client.prototype.send = function(destination, headers, body) {
            if (headers == null) {
                headers = {};
            }
            if (body == null) {
                body = '';
            }
            headers.destination = destination;
            return this._transmit("SEND", headers, body);
        };
        Client.prototype.subscribe = function(destination, callback, headers) {
            var client;
            if (headers == null) {
                headers = {};
            }
            if (!headers.id) {
                headers.id = "sub-" + this.counter++;
            }
            headers.destination = destination;
            this.subscriptions[headers.id] = callback;
            this._transmit("SUBSCRIBE", headers);
            client = this;
            return {
                id: headers.id,
                unsubscribe: function() {
                    return client.unsubscribe(headers.id);
                }
            };
        };
        Client.prototype.unsubscribe = function(id) {
            delete this.subscriptions[id];
            return this._transmit("UNSUBSCRIBE", {
                id: id
            });
        };
        Client.prototype.begin = function(transaction) {
            var client, txid;
            txid = transaction || "tx-" + this.counter++;
            this._transmit("BEGIN", {
                transaction: txid
            });
            client = this;
            return {
                id: txid,
                commit: function() {
                    return client.commit(txid);
                },
                abort: function() {
                    return client.abort(txid);
                }
            };
        };
        Client.prototype.commit = function(transaction) {
            return this._transmit("COMMIT", {
                transaction: transaction
            });
        };
        Client.prototype.abort = function(transaction) {
            return this._transmit("ABORT", {
                transaction: transaction
            });
        };
        Client.prototype.ack = function(messageID, subscription, headers) {
            if (headers == null) {
                headers = {};
            }
            headers["message-id"] = messageID;
            headers.subscription = subscription;
            return this._transmit("ACK", headers);
        };
        Client.prototype.nack = function(messageID, subscription, headers) {
            if (headers == null) {
                headers = {};
            }
            headers["message-id"] = messageID;
            headers.subscription = subscription;
            return this._transmit("NACK", headers);
        };
        return Client;
    })();
    Stomp = {
        VERSIONS: {
            V1_0: '1.0',
            V1_1: '1.1',
            V1_2: '1.2',
            supportedVersions: function() {
                return '1.1,1.0';
            }
        },
        client: function(url, protocols) {
            var klass, ws;
            if (protocols == null) {
                protocols = ['v10.stomp', 'v11.stomp'];
            }
            klass = Stomp.WebSocketClass || WebSocket;
            ws = new klass(url, protocols);
            return new Client(ws);
        },
        over: function(ws) {
            return new Client(ws);
        },
        Frame: Frame
    };
    if (typeof window !== "undefined" && window !== null) {
        Stomp.setInterval = function(interval, f) {
            return window.setInterval(f, interval);
        };
        Stomp.clearInterval = function(id) {
            return window.clearInterval(id);
        };
        window.Stomp = Stomp;
    } else if (typeof exports !== "undefined" && exports !== null) {
        exports.Stomp = Stomp;
    } else {
        self.Stomp = Stomp;
    }
}).call(this);
if (Date.now === undefined) {
    Date.now = function() {
        return new Date().valueOf();
    };
}
var TWEEN = TWEEN || (function() {
    var _tweens = [];
    return {
        REVISION: '12',
        getAll: function() {
            return _tweens;
        },
        removeAll: function() {
            _tweens = [];
        },
        add: function(tween) {
            _tweens.push(tween);
        },
        remove: function(tween) {
            var i = _tweens.indexOf(tween);
            if (i !== -1) {
                _tweens.splice(i, 1);
            }
        },
        update: function(time) {
            if (_tweens.length === 0) return false;
            var i = 0;
            time = time !== undefined ? time : (typeof window !== 'undefined' && window.performance !== undefined && window.performance.now !== undefined ? window.performance.now() : Date.now());
            while (i < _tweens.length) {
                if (_tweens[i].update(time)) {
                    i++;
                } else {
                    _tweens.splice(i, 1);
                }
            }
            return true;
        }
    };
})();
TWEEN.Tween = function(object) {
    var _object = object;
    var _valuesStart = {};
    var _valuesEnd = {};
    var _valuesStartRepeat = {};
    var _duration = 1000;
    var _repeat = 0;
    var _yoyo = false;
    var _isPlaying = false;
    var _reversed = false;
    var _delayTime = 0;
    var _startTime = null;
    var _easingFunction = TWEEN.Easing.Linear.None;
    var _interpolationFunction = TWEEN.Interpolation.Linear;
    var _chainedTweens = [];
    var _onStartCallback = null;
    var _onStartCallbackFired = false;
    var _onUpdateCallback = null;
    var _onCompleteCallback = null;
    for (var field in object) {
        _valuesStart[field] = parseFloat(object[field], 10);
    }
    this.to = function(properties, duration) {
        if (duration !== undefined) {
            _duration = duration;
        }
        _valuesEnd = properties;
        return this;
    };
    this.start = function(time) {
        TWEEN.add(this);
        _isPlaying = true;
        _onStartCallbackFired = false;
        _startTime = time !== undefined ? time : (typeof window !== 'undefined' && window.performance !== undefined && window.performance.now !== undefined ? window.performance.now() : Date.now());
        _startTime += _delayTime;
        for (var property in _valuesEnd) {
            if (_valuesEnd[property] instanceof Array) {
                if (_valuesEnd[property].length === 0) {
                    continue;
                }
                _valuesEnd[property] = [_object[property]].concat(_valuesEnd[property]);
            }
            _valuesStart[property] = _object[property];
            if ((_valuesStart[property] instanceof Array) === false) {
                _valuesStart[property] *= 1.0;
            }
            _valuesStartRepeat[property] = _valuesStart[property] || 0;
        }
        return this;
    };
    this.stop = function() {
        if (!_isPlaying) {
            return this;
        }
        TWEEN.remove(this);
        _isPlaying = false;
        this.stopChainedTweens();
        return this;
    };
    this.stopChainedTweens = function() {
        for (var i = 0, numChainedTweens = _chainedTweens.length; i < numChainedTweens; i++) {
            _chainedTweens[i].stop();
        }
    };
    this.delay = function(amount) {
        _delayTime = amount;
        return this;
    };
    this.repeat = function(times) {
        _repeat = times;
        return this;
    };
    this.yoyo = function(yoyo) {
        _yoyo = yoyo;
        return this;
    };
    this.easing = function(easing) {
        _easingFunction = easing;
        return this;
    };
    this.interpolation = function(interpolation) {
        _interpolationFunction = interpolation;
        return this;
    };
    this.chain = function() {
        _chainedTweens = arguments;
        return this;
    };
    this.onStart = function(callback) {
        _onStartCallback = callback;
        return this;
    };
    this.onUpdate = function(callback) {
        _onUpdateCallback = callback;
        return this;
    };
    this.onComplete = function(callback) {
        _onCompleteCallback = callback;
        return this;
    };
    this.update = function(time) {
        var property;
        if (time < _startTime) {
            return true;
        }
        if (_onStartCallbackFired === false) {
            if (_onStartCallback !== null) {
                _onStartCallback.call(_object);
            }
            _onStartCallbackFired = true;
        }
        var elapsed = (time - _startTime) / _duration;
        elapsed = elapsed > 1 ? 1 : elapsed;
        var value = _easingFunction(elapsed);
        for (property in _valuesEnd) {
            var start = _valuesStart[property] || 0;
            var end = _valuesEnd[property];
            if (end instanceof Array) {
                _object[property] = _interpolationFunction(end, value);
            } else {
                if (typeof(end) === "string") {
                    end = start + parseFloat(end, 10);
                }
                if (typeof(end) === "number") {
                    _object[property] = start + (end - start) * value;
                }
            }
        }
        if (_onUpdateCallback !== null) {
            _onUpdateCallback.call(_object, value);
        }
        if (elapsed == 1) {
            if (_repeat > 0) {
                if (isFinite(_repeat)) {
                    _repeat--;
                }
                for (property in _valuesStartRepeat) {
                    if (typeof(_valuesEnd[property]) === "string") {
                        _valuesStartRepeat[property] = _valuesStartRepeat[property] + parseFloat(_valuesEnd[property], 10);
                    }
                    if (_yoyo) {
                        var tmp = _valuesStartRepeat[property];
                        _valuesStartRepeat[property] = _valuesEnd[property];
                        _valuesEnd[property] = tmp;
                        _reversed = !_reversed;
                    }
                    _valuesStart[property] = _valuesStartRepeat[property];
                }
                _startTime = time + _delayTime;
                return true;
            } else {
                if (_onCompleteCallback !== null) {
                    _onCompleteCallback.call(_object);
                }
                for (var i = 0, numChainedTweens = _chainedTweens.length; i < numChainedTweens; i++) {
                    _chainedTweens[i].start(time);
                }
                return false;
            }
        }
        return true;
    };
};
TWEEN.Easing = {
    Linear: {
        None: function(k) {
            return k;
        }
    },
    Quadratic: {
        In: function(k) {
            return k * k;
        },
        Out: function(k) {
            return k * (2 - k);
        },
        InOut: function(k) {
            if ((k *= 2) < 1) return 0.5 * k * k;
            return -0.5 * (--k * (k - 2) - 1);
        }
    },
    Cubic: {
        In: function(k) {
            return k * k * k;
        },
        Out: function(k) {
            return --k * k * k + 1;
        },
        InOut: function(k) {
            if ((k *= 2) < 1) return 0.5 * k * k * k;
            return 0.5 * ((k -= 2) * k * k + 2);
        }
    },
    Quartic: {
        In: function(k) {
            return k * k * k * k;
        },
        Out: function(k) {
            return 1 - (--k * k * k * k);
        },
        InOut: function(k) {
            if ((k *= 2) < 1) return 0.5 * k * k * k * k;
            return -0.5 * ((k -= 2) * k * k * k - 2);
        }
    },
    Quintic: {
        In: function(k) {
            return k * k * k * k * k;
        },
        Out: function(k) {
            return --k * k * k * k * k + 1;
        },
        InOut: function(k) {
            if ((k *= 2) < 1) return 0.5 * k * k * k * k * k;
            return 0.5 * ((k -= 2) * k * k * k * k + 2);
        }
    },
    Sinusoidal: {
        In: function(k) {
            return 1 - Math.cos(k * Math.PI / 2);
        },
        Out: function(k) {
            return Math.sin(k * Math.PI / 2);
        },
        InOut: function(k) {
            return 0.5 * (1 - Math.cos(Math.PI * k));
        }
    },
    Exponential: {
        In: function(k) {
            return k === 0 ? 0 : Math.pow(1024, k - 1);
        },
        Out: function(k) {
            return k === 1 ? 1 : 1 - Math.pow(2, -10 * k);
        },
        InOut: function(k) {
            if (k === 0) return 0;
            if (k === 1) return 1;
            if ((k *= 2) < 1) return 0.5 * Math.pow(1024, k - 1);
            return 0.5 * (-Math.pow(2, -10 * (k - 1)) + 2);
        }
    },
    Circular: {
        In: function(k) {
            return 1 - Math.sqrt(1 - k * k);
        },
        Out: function(k) {
            return Math.sqrt(1 - (--k * k));
        },
        InOut: function(k) {
            if ((k *= 2) < 1) return -0.5 * (Math.sqrt(1 - k * k) - 1);
            return 0.5 * (Math.sqrt(1 - (k -= 2) * k) + 1);
        }
    },
    Elastic: {
        In: function(k) {
            var s, a = 0.1,
                p = 0.4;
            if (k === 0) return 0;
            if (k === 1) return 1;
            if (!a || a < 1) {
                a = 1;
                s = p / 4;
            } else s = p * Math.asin(1 / a) / (2 * Math.PI);
            return -(a * Math.pow(2, 10 * (k -= 1)) * Math.sin((k - s) * (2 * Math.PI) / p));
        },
        Out: function(k) {
            var s, a = 0.1,
                p = 0.4;
            if (k === 0) return 0;
            if (k === 1) return 1;
            if (!a || a < 1) {
                a = 1;
                s = p / 4;
            } else s = p * Math.asin(1 / a) / (2 * Math.PI);
            return (a * Math.pow(2, -10 * k) * Math.sin((k - s) * (2 * Math.PI) / p) + 1);
        },
        InOut: function(k) {
            var s, a = 0.1,
                p = 0.4;
            if (k === 0) return 0;
            if (k === 1) return 1;
            if (!a || a < 1) {
                a = 1;
                s = p / 4;
            } else s = p * Math.asin(1 / a) / (2 * Math.PI);
            if ((k *= 2) < 1) return -0.5 * (a * Math.pow(2, 10 * (k -= 1)) * Math.sin((k - s) * (2 * Math.PI) / p));
            return a * Math.pow(2, -10 * (k -= 1)) * Math.sin((k - s) * (2 * Math.PI) / p) * 0.5 + 1;
        }
    },
    Back: {
        In: function(k) {
            var s = 1.70158;
            return k * k * ((s + 1) * k - s);
        },
        Out: function(k) {
            var s = 1.70158;
            return --k * k * ((s + 1) * k + s) + 1;
        },
        InOut: function(k) {
            var s = 1.70158 * 1.525;
            if ((k *= 2) < 1) return 0.5 * (k * k * ((s + 1) * k - s));
            return 0.5 * ((k -= 2) * k * ((s + 1) * k + s) + 2);
        }
    },
    Bounce: {
        In: function(k) {
            return 1 - TWEEN.Easing.Bounce.Out(1 - k);
        },
        Out: function(k) {
            if (k < (1 / 2.75)) {
                return 7.5625 * k * k;
            } else if (k < (2 / 2.75)) {
                return 7.5625 * (k -= (1.5 / 2.75)) * k + 0.75;
            } else if (k < (2.5 / 2.75)) {
                return 7.5625 * (k -= (2.25 / 2.75)) * k + 0.9375;
            } else {
                return 7.5625 * (k -= (2.625 / 2.75)) * k + 0.984375;
            }
        },
        InOut: function(k) {
            if (k < 0.5) return TWEEN.Easing.Bounce.In(k * 2) * 0.5;
            return TWEEN.Easing.Bounce.Out(k * 2 - 1) * 0.5 + 0.5;
        }
    }
};
TWEEN.Interpolation = {
    Linear: function(v, k) {
        var m = v.length - 1,
            f = m * k,
            i = Math.floor(f),
            fn = TWEEN.Interpolation.Utils.Linear;
        if (k < 0) return fn(v[0], v[1], f);
        if (k > 1) return fn(v[m], v[m - 1], m - f);
        return fn(v[i], v[i + 1 > m ? m : i + 1], f - i);
    },
    Bezier: function(v, k) {
        var b = 0,
            n = v.length - 1,
            pw = Math.pow,
            bn = TWEEN.Interpolation.Utils.Bernstein,
            i;
        for (i = 0; i <= n; i++) {
            b += pw(1 - k, n - i) * pw(k, i) * v[i] * bn(n, i);
        }
        return b;
    },
    CatmullRom: function(v, k) {
        var m = v.length - 1,
            f = m * k,
            i = Math.floor(f),
            fn = TWEEN.Interpolation.Utils.CatmullRom;
        if (v[0] === v[m]) {
            if (k < 0) i = Math.floor(f = m * (1 + k));
            return fn(v[(i - 1 + m) % m], v[i], v[(i + 1) % m], v[(i + 2) % m], f - i);
        } else {
            if (k < 0) return v[0] - (fn(v[0], v[0], v[1], v[1], -f) - v[0]);
            if (k > 1) return v[m] - (fn(v[m], v[m], v[m - 1], v[m - 1], f - m) - v[m]);
            return fn(v[i ? i - 1 : 0], v[i], v[m < i + 1 ? m : i + 1], v[m < i + 2 ? m : i + 2], f - i);
        }
    },
    Utils: {
        Linear: function(p0, p1, t) {
            return (p1 - p0) * t + p0;
        },
        Bernstein: function(n, i) {
            var fc = TWEEN.Interpolation.Utils.Factorial;
            return fc(n) / fc(i) / fc(n - i);
        },
        Factorial: (function() {
            var a = [1];
            return function(n) {
                var s = 1,
                    i;
                if (a[n]) return a[n];
                for (i = n; i > 1; i--) s *= i;
                return a[n] = s;
            };
        })(),
        CatmullRom: function(p0, p1, p2, p3, t) {
            var v0 = (p2 - p0) * 0.5,
                v1 = (p3 - p1) * 0.5,
                t2 = t * t,
                t3 = t * t2;
            return (2 * p1 - 2 * p2 + v0 + v1) * t3 + (-3 * p1 + 3 * p2 - 2 * v0 - v1) * t2 + v0 * t + p1;
        }
    }
};
(function() {
    var ZKBMap, asIsk, infoPopupTemplate, killTemplate, regionSelectorTemplate, __bind = function(fn, me) {
            return function() {
                return fn.apply(me, arguments);
            };
        },
        __indexOf = [].indexOf || function(item) {
            for (var i = 0, l = this.length; i < l; i++) {
                if (i in this && this[i] === item) return i;
            }
            return -1;
        };
    killTemplate = Handlebars.compile("<div class='zkb-map-kill' id='zkb-map-kill-{{killID}}' data-solar-system='{{solarSystemID}}' >\n  <a href='//zkillboard.com/kill/{{killID}}/' target='_blank'>\n    <img src='https://image.eveonline.com/Type/{{killmail.victim.shipType.id}}_64.png'>\n  </a>\n  <a href='//zkillboard.com/character/{{killmail.victim.character.id}}/' target='_blank'>\n    <img src='https://image.eveonline.com/Character/{{killmail.victim.character.id}}_64.jpg'>\n  </a>\n  <a href='//zkillboard.com/corporation/{{killmail.victim.corporation.id}}/' target='_blank'>\n    <img src='https://image.eveonline.com/Corporation/{{killmail.victim.corporation.id}}_64.png'>\n  </a>\n  {{#if killmail.victim.alliance }}\n    <a href='//zkillboard.com/alliance/{{killmail.victim.alliance.id}}/' target='_blank'>\n      <img src='https://image.eveonline.com/Alliance/{{killmail.victim.alliance.id}}_64.png'>\n    </a>\n  {{/if}}\n</div>");
    regionSelectorTemplate = Handlebars.compile("<div class='zkb-map-region-selector'>\n  <ul>\n    {{#each groups}}\n      <li data-name='{{this.name}}'>{{this.name}}</li>\n    {{/each}}\n  </ul>\n</div>");
    infoPopupTemplate = Handlebars.compile("<div class='zkb-map-info-popup'>\n  <div class='zkb-map-info-popup-text'>\n    {{name}}\n    <br>\n    {{value}}\n  </div>\n  <img src='{{src}}'></img>\n</div>");
    asIsk = function(value) {
        var str;
        if (!value) {
            return;
        }
        str = value.toString();
        str = str.split('').reverse().join('');
        str = str.match(/.{1,3}/g).join(' ');
        str = str.split('').reverse().join('');
        return str + ' ISK';
    };
    ZKBMap = (function() {
        ZKBMap.prototype.allSolarSystems = {};
        ZKBMap.prototype.displayedSolarSystems = {};
        ZKBMap.prototype.regionGroups = [];
        ZKBMap.prototype.solarSystemPings = {};
        ZKBMap.prototype.killsReceivedFilter = {};

        function ZKBMap(options) {
            this.options = options;
            this.calibrateCamera = __bind(this.calibrateCamera, this);
            this.render = __bind(this.render, this);
            this.initializeCamera();
            this.initializeDOM();
            this.initializeColors();
            this.fetchData();
            this.initializeRotationControls();
            this.listenToKillFeed();
            this.render();
            setInterval(TWEEN.update, 15);
        }
        ZKBMap.prototype.initializeDOM = function() {
            this.options.container.addClass('zkb-map-container');
            this.domCanvas = $(this.renderer.domElement);
            this.domCanvas.addClass('zkb-map-canvas');
            this.options.container.append(this.domCanvas);
            this.domKillLog = $("<div class='zkb-map-kill-log'></div>");
            return this.options.container.append(this.domKillLog);
        };
        ZKBMap.prototype.initializeCamera = function() {
            this.galaxyScene = new THREE.Scene();
            this.pingScene = new THREE.Scene();
            this.renderer = new THREE.WebGLRenderer({
                antialias: true
            });
            this.renderer.autoClear = false;
            this.projector = new THREE.Projector();
            this.camera = new THREE.PerspectiveCamera(this.options.cameraFov);
            return this.calibrateCamera();
        };
        ZKBMap.prototype.initializeColors = function() {
            this.securityGradient = new Rainbow();
            this.securityGradient.setNumberRange(-1 * this.options.colorSteps, this.options.colorSteps);
            this.securityGradient.setSpectrum(this.options.lowSecColor, this.options.highSecColor);
            this.solarSystemMaterial = new THREE.ParticleBasicMaterial({
                vertexColors: true,
                size: 6,
                map: THREE.ImageUtils.loadTexture(this.options.starTextureUrl),
                blending: THREE.AdditiveBlending,
                transparent: true
            });
            return this.glowMaterial = new THREE.SpriteMaterial({
                map: THREE.ImageUtils.loadTexture(this.options.starTextureUrl),
                useScreenCoordinates: false,
                color: new THREE.Color(this.options.killFlareColor),
                blending: THREE.AdditiveBlending
            });
        };
        ZKBMap.prototype.securityToColor = function(security) {
            return new THREE.Color("#" + (this.securityGradient.colourAt(Math.round(security * this.options.colorSteps))));
        };
        ZKBMap.prototype.emptyScenes = function() {
            var object, ping, systemId, _i, _len, _ref, _ref1, _ref2;
            _ref = this.solarSystemPings;
            for (systemId in _ref) {
                ping = _ref[systemId];
                if ((_ref1 = ping.animation) != null) {
                    _ref1.stop();
                }
                this.pingScene.remove(ping.glow);
            }
            _ref2 = this.galaxyScene.children;
            for (_i = 0, _len = _ref2.length; _i < _len; _i++) {
                object = _ref2[_i];
                this.galaxyScene.remove(object);
            }
            return this.domKillLog.html('');
        };
        ZKBMap.prototype.fetchData = function() {
            var regionGroupsCallback, solarSystemCallback, _this = this;
            solarSystemCallback = function(items) {
                var item, solarSystem, _i, _len, _results;
                _results = [];
                for (_i = 0, _len = items.length; _i < _len; _i++) {
                    item = items[_i];
                    solarSystem = {
                        id: item[0],
                        position: new THREE.Vector3(item[1], item[3], item[2]),
                        security: item[4],
                        region: item[5],
                        name: item[6]
                    };
                    _results.push(_this.allSolarSystems[solarSystem.id] = solarSystem);
                }
                return _results;
            };
            regionGroupsCallback = function(items) {
                var item, regionGroup, _i, _len, _results;
                _results = [];
                for (_i = 0, _len = items.length; _i < _len; _i++) {
                    item = items[_i];
                    regionGroup = {
                        name: item[0],
                        center: new THREE.Vector3(item[1], item[3], item[2]),
                        regions: item[4],
                        minSecurity: item[5],
                        maxSecurity: item[6]
                    };
                    _results.push(_this.regionGroups.push(regionGroup));
                }
                return _results;
            };
            return $.when($.getJSON(this.options.solarSystemsJsonUrl, solarSystemCallback), $.getJSON(this.options.regionGroupsJsonUrl, regionGroupsCallback)).then(function() {
                if (_this.options.showRegionSwitcher) {
                    _this.initializeRegionSelector();
                }
                return _this.displayRegionGroup(_this.regionGroups[0].name);
            });
        };
        ZKBMap.prototype.initializeRegionSelector = function() {
            var _this = this;
            this.domRegionSelector = $(regionSelectorTemplate({
                groups: this.regionGroups
            }));
            this.options.container.prepend(this.domRegionSelector);
            return this.domRegionSelector.on('click', 'li', function(e) {
                return _this.displayRegionGroup($.trim($(e.target).html()));
            });
        };
        ZKBMap.prototype.displayRegionGroup = function(regionGroupName) {
            var clonedSolarSystem, galaxy, geometry, maxOffset, regionGroup, rg, solarSystem, solarSystemID, _i, _len, _ref, _ref1, _ref2, _ref3;
            regionGroup = null;
            _ref = this.regionGroups;
            for (_i = 0, _len = _ref.length; _i < _len; _i++) {
                rg = _ref[_i];
                if (rg.name === regionGroupName) {
                    regionGroup = rg;
                    break;
                }
            }
            if (!regionGroup) {
                return;
            }
            if (this.options.showRegionSwitcher) {
                this.domRegionSelector.find('li').removeClass('current');
                this.domRegionSelector.find("li[data-name='" + regionGroup.name + "']").addClass('current');
            }
            this.displayedSolarSystems = {};
            geometry = new THREE.Geometry();
            maxOffset = 0;
            _ref1 = this.allSolarSystems;
            for (solarSystemID in _ref1) {
                solarSystem = _ref1[solarSystemID];
                if ((_ref2 = solarSystem.region, __indexOf.call(regionGroup.regions, _ref2) >= 0) && (regionGroup.minSecurity <= (_ref3 = solarSystem.security) && _ref3 <= regionGroup.maxSecurity)) {
                    clonedSolarSystem = {
                        position: solarSystem.position.clone()
                    };
                    clonedSolarSystem.position.sub(regionGroup.center);
                    this.displayedSolarSystems[solarSystemID] = clonedSolarSystem;
                    geometry.vertices.push(clonedSolarSystem.position);
                    geometry.colors.push(this.securityToColor(solarSystem.security));
                    maxOffset = Math.max(maxOffset, Math.abs(clonedSolarSystem.position.x), Math.abs(clonedSolarSystem.position.y), Math.abs(clonedSolarSystem.position.z));
                }
            }
            galaxy = new THREE.ParticleSystem(geometry, this.solarSystemMaterial);
            galaxy.sortParticles = true;
            this.emptyScenes();
            this.galaxyScene.add(galaxy);
            this.camera.position = new THREE.Vector3(0, 0, maxOffset + 100);
            return this.camera.lookAt(new THREE.Vector3(0, 0, 0));
        };
        ZKBMap.prototype.initializeRotationControls = function() {
            return this.controls = new THREE.OrbitControls(this.camera, this.domCanvas[0]);
        };
        ZKBMap.prototype.ping = function(kill) {
            var currentPing, fade, flare, maxFlare, minFlare, solarSystem, systemId, _ref, _this = this;
            systemId = kill.killmail.solarSystem.id;
            solarSystem = this.displayedSolarSystems[systemId];
            if (!solarSystem) {
		return this.addToKillLog(kill);
            }
            currentPing = this.solarSystemPings[systemId] || {};
            if (!currentPing.glow) {
                currentPing.glow = new THREE.Sprite(this.glowMaterial);
                currentPing.glow.position = solarSystem.position;
                this.pingScene.add(currentPing.glow);
            }
            if ((_ref = currentPing.animation) != null) {
                _ref.stop();
            }
            maxFlare = new THREE.Vector3(this.options.killFlareSize, this.options.killFlareSize);
            minFlare = new THREE.Vector3(0, 0);
            flare = new TWEEN.Tween(currentPing.glow.scale).to(maxFlare, this.options.killFlareDuration).easing(TWEEN.Easing.Exponential.In);
            fade = new TWEEN.Tween(currentPing.glow.scale).to(minFlare, this.options.killFadeDuration).easing(TWEEN.Easing.Exponential.Out);
            flare.onComplete(function() {
                fade.start();
                return currentPing.animation = fade;
            });
            fade.onComplete(function() {
                _this.pingScene.remove(currentPing.glow);
                return delete _this.solarSystemPings[systemId];
            });
            currentPing.animation = flare;
            flare.start();
            this.solarSystemPings[systemId] = currentPing;
            return this.addToKillLog(kill);
        };
        ZKBMap.prototype.addToKillLog = function(kill) {
            var infoPopupDom, killDom, _ref, _this = this;
	    if (kill.killmail.victim.character == null) {
		kill.killmail.victim.character = { id: 1 };
	    }
            killDom = $(killTemplate(kill));
            infoPopupDom = $(infoPopupTemplate({
                src: this.options.infoPopupTextureUrl,
                name: (_ref = this.allSolarSystems[kill.killmail.solarSystem.id]) != null ? _ref.name : void 0,
                involved: kill.killmail.involved,
                value: asIsk(kill.zkb.totalValue)
            }));
            this.domKillLog.prepend(killDom);
            killDom.fadeIn(this.options.killFlareDuration, function() {
                return killDom.fadeOut(_this.options.killFadeDuration * 1.5, function() {
                    killDom.remove();
                    infoPopupDom.remove();
                    return delete _this.killsReceivedFilter[kill.killID];
                });
            });
            killDom.mouseover(function() {
                infoPopupDom.css(_this.infoPopupLocation(kill.solarSystemID));
                return _this.options.container.append(infoPopupDom);
            });
            return killDom.mouseout(function() {
                return infoPopupDom.detach();
            });
        };
        ZKBMap.prototype.render = function() {
            this.controls.update();
            this.renderer.clear();
            this.renderer.render(this.galaxyScene, this.camera);
            this.renderer.clear(false, true, false);
            this.renderer.render(this.pingScene, this.camera);
            return requestAnimationFrame(this.render);
        };
        ZKBMap.prototype.calibrateCamera = function() {
            this.camera.aspect = this.options.container.innerWidth() / this.options.container.innerHeight();
            this.camera.updateProjectionMatrix();
            return this.renderer.setSize(this.options.container.innerWidth(), this.options.container.innerHeight());
        };
        ZKBMap.prototype.listenToKillFeed = function() {
            var e, _this = this;
            try {
                this.client = new ReconnectingWebSocket(this.options.killFeedWebsocket);
                return this.client.onmessage = function(event) {
		    try {
                    	var kill;
                    	kill = JSON.parse(event.data);
                    	if (_this.killsReceivedFilter[kill.killID]) {
                    	    return;
                    	}
                    	_this.killsReceivedFilter[kill.killID] = true;
		    	var o = _this.ping(kill);
                    	return o;
		    } catch (error) { 
			console.error(error);
			return;
		    }
                };
            } catch (_error) {
                e = _error;
                return console.error(e);
            }
        };
        ZKBMap.prototype.infoPopupLocation = function(systemId) {
            var css, position, projected, scaleX, scaleY;
	try {
            position = this.displayedSolarSystems[systemId].position.clone();
            projected = this.projector.projectVector(position, this.camera);
            scaleX = (projected.x + 1) / 2;
            scaleY = (-projected.y + 1) / 2;
            return css = {
                top: this.options.container.innerHeight() * scaleY,
                left: this.options.container.innerWidth() * scaleX
            };
	} catch (error) { 

		console.log('position error - moving on');
		return css = { top: 100000, left: 10000};
		}
        };
        return ZKBMap;
    })();
    $.fn.ZKBMap = function(options) {
        var defaults;
        defaults = {
            container: this,
            cameraFov: 90,
            solarSystemsJsonUrl: '/data/solar_systems.json',
            regionGroupsJsonUrl: '/data/region_groups.json',
            starTextureUrl: '/img/glow.png',
            colorSteps: 20,
            lowSecColor: '#999999',
            highSecColor: '#CCCCCC',
            killFeedWebsocket: 'wss://api.pizza.moe/stream/killmails/ ',
            killFlareSize: 100,
            killFlareDuration: 500,
            killFadeDuration: 15000,
            killFlareColor: '#FF0000',
            showRegionSwitcher: true,
            infoPopupTextureUrl: '/img/pointer.png'
        };
        return new ZKBMap($.extend(defaults, options));
    };
}).call(this);
