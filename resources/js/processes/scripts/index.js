import Vue from "vue";
import ScriptListing from "./components/ScriptListing";
import CategorySelect from "../categories/components/CategorySelect";

Vue.component('category-select', CategorySelect);

new Vue({
    el: "#scriptIndex",
    data: {
        filter: ""
    },
    components: {
        ScriptListing
    },
    methods: {
        __(variable) {
            return __(variable);
        },
        deleteScript(data) {
            ProcessMaker.apiClient.delete(`scripts/${data.id}`)
                .then((response) => {
                    ProcessMaker.alert(this.$t("The script was deleted."), "success");
                    this.reload();
                });
        },
        reload() {
            this.$refs.listScript.fetch();
        }
    }
});
