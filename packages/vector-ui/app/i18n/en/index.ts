import actions from './actions'
import common from './common'
import dashboard from './dashboard'
import fields from './fields'
import jobs from './jobs'
import list from './list'
import record from './record'
import settings from './settings'
import shell from './shell'

/** English, the fallback for every other locale: one object per area of the UI. */
export default { common, shell, list, record, actions, dashboard, jobs, settings, fields }
