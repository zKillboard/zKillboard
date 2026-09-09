"use strict";

/*
 * A stripped down copy of protobuf.js's reader.
 *
 * The minimal version of protobuf.js is 100KB. But most of the stuff is not used.
 * Additionally, it has no way to read from a stream. This version addresses both:
 * only implement that what we actually need, and read from a stream when needed.
 *
 * The smaller size means the javascript loads faster, and the streaming means the
 * decoding can start on the first chunk (instead of when all data arrived).
 *
 * NOTE: this is not a generic Protobuf loader, and when you feed it broken protobuf
 * files, it will crash in unexpected ways.
 *
 * Original source: https://github.com/protobufjs/protobuf.js/blob/master/src/reader.js
 */

export default Reader;

function Reader(reader) {
    this._reader = reader;
    this._buf = new Uint8Array();
    this._next_buf = null;
    this._buf_pos = 0;
    this._eof = false;

    this.pos = 0;
    this.len = 0;
}

Reader.create = function create(reader) {
    return new Reader(reader);
}

Reader.prototype.need_data = function need_data() {
    return this._buf.length - this._buf_pos < 2048 && this._next_buf === null;
}

Reader.prototype.fetch_data = async function fetch_data() {
    if (this._buf.length - this._buf_pos >= 2048) return;
    if (this._next_buf !== null) return;

    const values = [];
    let length = 0;

    /* Read for at least 2048 bytes; we know all objects are smaller. */
    while (length < 2048) {
        const {done, value} = await this._reader.read();
        if (done) {
            break;
        }

        values.push(value);
        length += value.length;
    }

    /* If we read nothing, fast-path into an end-of-file marker. */
    if (length === 0) {
        this._next_buf = new Uint8Array();
        this._eof = true;
        return;
    }

    /* If we read only one chunk, we can use it directly. */
    if (values.length === 1) {
        this._next_buf = values[0];
        return;
    }

    /* Multiple chunks, copy them into a single buffer. */
    this._next_buf = new Uint8Array(length);
    let pos = 0;
    for (const value of values) {
        this._next_buf.set(value, pos);
        pos += value.length;
    }
}

Reader.prototype.is_eof = function is_eof() {
    return this._eof && this._buf_pos >= this._buf.length;
}

Reader.prototype.read = function read(len) {
    if (this._next_buf === null) return this._buf;

    if (this._buf_pos >= this._buf.length) {
        this._buf_pos -= this._buf.length;
        this._buf = this._next_buf;

        this._next_buf = null;
    } else if (this._buf_pos + len >= this._buf.length) {
        this._buf = this._buf.slice(this._buf_pos);
        this._buf_pos = 0;
        return new Uint8Array([...this._buf, ...this._next_buf.slice(0, len - this._buf.length)]);
    }

    return this._buf;
}

Reader.prototype.uint32 = (function read_uint32_setup() {
    var value = 4294967295; // optimizer type-hint, tends to deopt otherwise (?!)
    return function read_uint32() {
        const buf = this.read(10);

        value = (         buf[this._buf_pos] & 127       ) >>> 0; this.pos++; if (buf[this._buf_pos++] < 128) return value;
        value = (value | (buf[this._buf_pos] & 127) <<  7) >>> 0; this.pos++; if (buf[this._buf_pos++] < 128) return value;
        value = (value | (buf[this._buf_pos] & 127) << 14) >>> 0; this.pos++; if (buf[this._buf_pos++] < 128) return value;
        value = (value | (buf[this._buf_pos] & 127) << 21) >>> 0; this.pos++; if (buf[this._buf_pos++] < 128) return value;
        value = (value | (buf[this._buf_pos] &  15) << 28) >>> 0; this.pos++; if (buf[this._buf_pos++] < 128) return value;

        this.pos += 5;
        this._buf_pos += 5;
        return value;
    };
})();

Reader.prototype.int32 = function read_int32() {
    return this.uint32() | 0;
};

Reader.prototype.bool = function read_bool() {
    return this.uint32() !== 0;
}

Reader.prototype.bytes = function read_bytes() {
    var length = this.uint32();

    const buf = this.read(length);
    this.pos += length;

    const res = buf.slice(this._buf_pos, this._buf_pos + length);
    this._buf_pos += length;
    return res;
};

Reader.prototype.string = function read_string() {
    var bytes = this.bytes();
    var str = "";
    for (var i = 0; i < bytes.length; i++) {
        str += String.fromCharCode(bytes[i]);
    }
    return str;
};

let f32 = new Float32Array([ -0 ]), f8b = new Uint8Array(f32.buffer);

Reader.prototype.float = function read_float() {
    const buf = this.read(4);
    this.pos += 4;

    f8b[0] = buf[this._buf_pos++];
    f8b[1] = buf[this._buf_pos++];
    f8b[2] = buf[this._buf_pos++];
    f8b[3] = buf[this._buf_pos++];
    return f32[0];
};

Reader.prototype.skipType = function(wireType) {
    throw Error("Datafile appears to be corrupted.");
};
