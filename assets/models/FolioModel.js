/**
 * Shared runtime base for generated folio active-record classes (see folio:generate-js-models).
 * Hand-written, ships once -- generated classes (Row, Core, etc.) only declare tableName/columns/
 * relations and extend this. Deliberately NOT an ORM dependency (Knex/Bookshelf/Sequelize have no
 * real sqlite-wasm support -- see survos-sites/sos discussion) -- just enough active-record shape
 * (Model.where(...).all(db), row.core()) backed directly by sqlite-wasm's synchronous db.exec().
 *
 * No promises anywhere on purpose: sqlite-wasm's main-thread API is synchronous, so wrapping it in
 * async ceremony (what every browser-oriented ORM wrapper does, being built for Worker-based
 * drivers) would be pure overhead for a POC that's deliberately not using OPFS/Workers yet.
 */
export class FolioModel {
    /** Overridden by generated subclasses. */
    static tableName = null;

    /** Overridden by generated subclasses: raw SQL column names, in table order. */
    static columns = [];

    constructor(row) {
        Object.assign(this, row);
    }

    /** @returns {FolioQuery} */
    static where(conditions = {}) {
        return new FolioQuery(this, conditions);
    }

    /** @returns {FolioModel[]} */
    static all(db) {
        return this.where().all(db);
    }

    /** @returns {FolioModel|null} */
    static find(db, id) {
        return this.where({ id }).first(db);
    }
}

class FolioQuery {
    constructor(ModelClass, conditions = {}) {
        this.Model = ModelClass;
        this.conditions = conditions;
        this._orderBy = null;
        this._limit = null;
    }

    orderBy(column, direction = 'ASC') {
        this._orderBy = [column, direction];
        return this;
    }

    limit(n) {
        this._limit = n;
        return this;
    }

    /** @returns {FolioModel[]} */
    all(db) {
        const { sql, bind } = this.toSql();
        const rows = [];
        db.exec({ sql, bind, rowMode: 'object', callback: (row) => rows.push(new this.Model(row)) });
        return rows;
    }

    /** @returns {FolioModel|null} */
    first(db) {
        return this.limit(1).all(db)[0] ?? null;
    }

    toSql() {
        const cols = Object.keys(this.conditions);
        const where = cols.length ? ` WHERE ${cols.map((c) => `${c} = ?`).join(' AND ')}` : '';
        const order = this._orderBy ? ` ORDER BY ${this._orderBy[0]} ${this._orderBy[1]}` : '';
        const limit = this._limit ? ` LIMIT ${this._limit}` : '';

        return {
            sql: `SELECT * FROM ${this.Model.tableName}${where}${order}${limit}`,
            bind: cols.map((c) => this.conditions[c]),
        };
    }
}
