export default function ({ vue, definePage }) {
    definePage('queues', { title: 'Queues', component: { setup: () => () => vue.h('p', 'queues') } })
}
