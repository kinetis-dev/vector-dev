export default defineNuxtRouteMiddleware((to) => {
    const runtime = useVectorRuntime()

    if (runtime.hosted) {
        return // the application gated the shell page before it was served
    }

    const auth = useVectorAuthStore()
    const appConfig = useAppConfig()

    auth.restore()

    if (!auth.isAuthenticated) {
        return navigateTo({ path: `${appConfig.vector.basePath || ''}/login`, query: { redirect: to.fullPath } })
    }
})
