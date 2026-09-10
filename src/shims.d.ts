declare module '*.vue' {
	import type { DefineComponent } from 'vue'
	const component: DefineComponent<object, object, any>
	export default component
}
declare module '*.svg?raw' {
	const content: string
	export default content
}
