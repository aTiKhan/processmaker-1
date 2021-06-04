<?php
namespace ProcessMaker\Http\Resources;

class Process extends ApiResource
{

    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request
     * @return array
     */
    public function toArray($request)
    {
        $array = parent::append('notifications', 'task_notifications')->toArray($request);
        $include = explode(',', $request->input('include', ''));
        if (in_array('user', $include)) {
            $array['user'] = new Users($this->user);
        }
        if (in_array('category', $include)) {
            $array['category'] = new ProcessCategory($this->category);
        }
        if (in_array('events', $include)) {
            $array['events'] = $this->getStartEvents(true);
        }
        if (in_array('svg', $include)) {
            $array['svg'] = $this->svg;
        }
        return $array;
    }
}
